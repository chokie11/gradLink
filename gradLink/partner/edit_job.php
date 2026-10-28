<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['partner']);

$user_id = $_SESSION['user_id'];
$job_id = $_GET['id'] ?? null;

if (!$job_id || !ctype_digit((string) $job_id)) {
    header('Location: job_posts.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM job_posts
    WHERE id = ? AND partner_id = ?
    LIMIT 1
");
$stmt->execute([$job_id, $user_id]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    header('Location: job_posts.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $requirements = trim($_POST['requirements'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $employment_type = trim($_POST['employment_type'] ?? 'Full-time');
    $salary_range = trim($_POST['salary_range'] ?? '');
    $deadline = trim($_POST['deadline'] ?? '');
    $status = trim($_POST['status'] ?? 'Open');

    if ($title === '' || $description === '' || $requirements === '') {
        $errors[] = 'Title, description, and requirements are required.';
    }

    if (!in_array($employment_type, ['Full-time', 'Part-time', 'Internship', 'Contract'], true)) {
        $errors[] = 'Invalid employment type.';
    }

    if (!in_array($status, ['Open', 'Closed'], true)) {
        $errors[] = 'Invalid status.';
    }

    if ($deadline !== '') {
        $deadlineDate = DateTime::createFromFormat('Y-m-d', $deadline);
        if (!$deadlineDate || $deadlineDate->format('Y-m-d') !== $deadline) {
            $errors[] = 'Invalid application deadline.';
        }
    }

    if (empty($errors)) {
        $updateStmt = $pdo->prepare("
            UPDATE job_posts
            SET title = ?, description = ?, requirements = ?, location = ?,
                employment_type = ?, salary_range = ?, deadline = ?, status = ?
            WHERE id = ? AND partner_id = ?
        ");

        $updateStmt->execute([
            $title,
            $description,
            $requirements,
            $location !== '' ? $location : null,
            $employment_type,
            $salary_range !== '' ? $salary_range : null,
            $deadline !== '' ? $deadline : null,
            $status,
            $job_id,
            $user_id
        ]);

        header('Location: job_posts.php');
        exit;
    }

    $job = array_merge($job, $_POST);
}

$applicantCount = 0;
$bestMatchScore = 0;
$totalRecommendations = 0;

try {
    $applicantStmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE job_post_id = ?");
    $applicantStmt->execute([$job_id]);
    $applicantCount = (int) $applicantStmt->fetchColumn();
} catch (Exception $e) {
    $applicantCount = 0;
}

try {
    $matchStmt = $pdo->prepare("
        SELECT COUNT(*) AS total_recommendations, COALESCE(MAX(match_score), 0) AS best_score
        FROM job_recommendations
        WHERE job_post_id = ?
    ");
    $matchStmt->execute([$job_id]);
    $matchStats = $matchStmt->fetch(PDO::FETCH_ASSOC);
    $totalRecommendations = (int) ($matchStats['total_recommendations'] ?? 0);
    $bestMatchScore = (int) round((float) ($matchStats['best_score'] ?? 0));
} catch (Exception $e) {
    $totalRecommendations = 0;
    $bestMatchScore = 0;
}

$postedTitle = $job['title'] ?? '';
$postedDescription = $job['description'] ?? '';
$postedRequirements = $job['requirements'] ?? '';
$postedLocation = $job['location'] ?? '';
$postedEmploymentType = $job['employment_type'] ?? 'Full-time';
$postedSalaryRange = $job['salary_range'] ?? '';
$postedDeadline = $job['deadline'] ?? '';
$postedStatus = $job['status'] ?? 'Open';

$pageTitle = 'Edit Job Post';
$pageSubtitle = 'Refine your job listing and keep applicant matching accurate.';
$activePage = 'jobs';

require_once '../includes/dashboard_header.php';
?>

<style>
    .partner-job-editor {
        --partner-gold: var(--maroon, #d08c0c);
        --partner-gold-dark: var(--dark-maroon, #be7f09);
        --partner-gold-soft: var(--soft-maroon, #e59d18);
        --partner-ink: #2c220f;
        --partner-muted: #746345;
        --partner-line: #f0d8a3;
        --partner-bg: #fffaf0;
    }

    .editor-hero {
        position: relative;
        overflow: hidden;
        border-radius: 30px;
        padding: 30px;
        margin-bottom: 22px;
        background:
            radial-gradient(circle at top right, rgba(229, 157, 24, .34), transparent 34%),
            linear-gradient(135deg, #ffffff 0%, #fff8e8 46%, #ffedbd 100%);
        border: 1px solid rgba(240, 216, 163, .96);
        box-shadow: 0 24px 60px rgba(126, 80, 0, .10);
    }

    .editor-hero::after {
        content: '';
        position: absolute;
        right: -70px;
        bottom: -100px;
        width: 240px;
        height: 240px;
        border-radius: 50%;
        background: rgba(208, 140, 12, .12);
    }

    .editor-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1.45fr) minmax(290px, .9fr);
        gap: 24px;
        align-items: stretch;
    }

    .partner-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255,255,255,.82);
        color: #8b5a02;
        font-size: 12px;
        font-weight: 900;
        letter-spacing: .08em;
        text-transform: uppercase;
        border: 1px solid rgba(240, 216, 163, .95);
    }

    .editor-title {
        margin-top: 14px;
        font-size: clamp(28px, 4vw, 42px);
        line-height: 1.02;
        font-weight: 950;
        letter-spacing: -.04em;
        color: var(--partner-ink);
    }

    .editor-text {
        margin-top: 12px;
        color: #6f5b37;
        font-size: 15px;
        line-height: 1.7;
        max-width: 760px;
    }

    .hero-actions,
    .form-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 20px;
    }

    .partner-btn-primary,
    .partner-btn-outline,
    .partner-btn-soft {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 44px;
        padding: 11px 16px;
        border-radius: 14px;
        font-weight: 850;
        text-decoration: none;
        border: 1px solid transparent;
        transition: all .18s ease;
        cursor: pointer;
    }

    .partner-btn-primary {
        background: linear-gradient(135deg, var(--partner-gold), var(--partner-gold-dark));
        color: #fff !important;
        box-shadow: 0 14px 28px rgba(208, 140, 12, .24);
        border: none;
    }

    .partner-btn-primary:hover {
        transform: translateY(-1px);
        color: #fff !important;
        box-shadow: 0 18px 34px rgba(208, 140, 12, .30);
    }

    .partner-btn-outline {
        background: #fff;
        color: #8b5a02 !important;
        border-color: var(--partner-line);
    }

    .partner-btn-outline:hover {
        background: #fff4d7;
        color: #6f4600 !important;
        transform: translateY(-1px);
    }

    .partner-btn-soft {
        background: #fff2cc;
        color: #8b5a02 !important;
        border-color: #f3d99f;
    }

    .job-health-card {
        border-radius: 24px;
        padding: 22px;
        background: rgba(255,255,255,.78);
        border: 1px solid rgba(240, 216, 163, .95);
        box-shadow: 0 18px 36px rgba(133, 91, 10, .08);
    }

    .health-title {
        font-weight: 950;
        color: var(--partner-ink);
        font-size: 20px;
        margin-bottom: 12px;
    }

    .health-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
    }

    .health-item {
        border-radius: 18px;
        padding: 14px;
        background: #fffaf0;
        border: 1px solid #f1d79d;
    }

    .health-value {
        font-size: 24px;
        font-weight: 950;
        color: #9f6505;
        line-height: 1;
    }

    .health-label {
        margin-top: 6px;
        color: #7c6b4f;
        font-size: 12px;
        font-weight: 800;
    }

    .editor-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 340px;
        gap: 22px;
        align-items: start;
    }

    .editor-card,
    .editor-side-card {
        border-radius: 26px;
        background: #fff;
        border: 1px solid rgba(240, 216, 163, .95);
        box-shadow: 0 18px 44px rgba(126, 80, 0, .08);
    }

    .editor-card {
        overflow: hidden;
    }

    .form-section {
        padding: 24px;
        border-bottom: 1px solid #f3e2bd;
    }

    .form-section:last-child {
        border-bottom: 0;
    }

    .section-heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }

    .section-heading h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 950;
        color: var(--partner-ink);
        letter-spacing: -.02em;
    }

    .section-heading p {
        margin: 5px 0 0;
        color: var(--partner-muted);
        font-size: 13px;
        line-height: 1.55;
    }

    .section-number {
        width: 36px;
        height: 36px;
        border-radius: 14px;
        background: linear-gradient(135deg, var(--partner-gold), var(--partner-gold-dark));
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 950;
        box-shadow: 0 10px 20px rgba(208,140,12,.22);
        flex: 0 0 36px;
    }

    .partner-job-editor .form-label {
        font-weight: 850;
        color: #4a3613;
        font-size: 13px;
        margin-bottom: 8px;
    }

    .partner-job-editor .form-control,
    .partner-job-editor .form-select {
        border: 1px solid #ead7a8;
        border-radius: 14px;
        padding: 12px 14px;
        background: #fffdfa;
        color: var(--partner-ink);
        box-shadow: none;
    }

    .partner-job-editor .form-control:focus,
    .partner-job-editor .form-select:focus {
        border-color: var(--partner-gold);
        box-shadow: 0 0 0 .2rem rgba(208, 140, 12, .14);
        background: #fff;
    }

    .input-hint {
        margin-top: 7px;
        color: #8a7858;
        font-size: 12px;
        line-height: 1.45;
    }

    .type-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
    }

    .type-option {
        position: relative;
        cursor: pointer;
        margin: 0;
    }

    .type-option input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .type-option span {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 52px;
        padding: 12px;
        border-radius: 16px;
        border: 1px solid #ead7a8;
        background: #fffdfa;
        color: #6c552b;
        font-weight: 850;
        transition: all .18s ease;
    }

    .type-option input:checked + span {
        color: #fff;
        background: linear-gradient(135deg, var(--partner-gold), var(--partner-gold-dark));
        border-color: var(--partner-gold-dark);
        box-shadow: 0 12px 24px rgba(208,140,12,.22);
        transform: translateY(-1px);
    }

    .alert-partner {
        border-radius: 20px;
        background: #fff3f0;
        border: 1px solid #f1beb5;
        color: #8a271c;
        padding: 16px 18px;
    }

    .submit-strip {
        padding: 20px 24px;
        background: #fffaf0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }

    .submit-note {
        color: #7c6b4f;
        font-size: 13px;
        line-height: 1.5;
    }

    .editor-side-card {
        padding: 22px;
        position: sticky;
        top: 88px;
    }

    .tips-title {
        margin: 0 0 14px;
        color: var(--partner-ink);
        font-size: 18px;
        font-weight: 950;
    }

    .tip-list {
        display: grid;
        gap: 10px;
        margin: 0 0 18px;
        padding: 0;
        list-style: none;
    }

    .tip-list li {
        padding: 13px;
        border-radius: 16px;
        background: #fffaf0;
        border: 1px solid #f1d79d;
        color: #6f5b37;
        font-size: 13px;
        line-height: 1.45;
    }

    .tip-list strong {
        display: block;
        color: #8b5a02;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: 3px;
    }

    .preview-card {
        border-radius: 20px;
        padding: 18px;
        background: linear-gradient(135deg, #fff8e8, #ffefc2);
        border: 1px solid #f0d8a3;
    }

    .preview-label {
        font-size: 11px;
        font-weight: 950;
        color: #8b5a02;
        text-transform: uppercase;
        letter-spacing: .08em;
        margin-bottom: 8px;
    }

    .preview-title {
        font-size: 18px;
        font-weight: 950;
        color: var(--partner-ink);
        line-height: 1.25;
    }

    .preview-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 12px;
    }

    .preview-chip {
        border-radius: 999px;
        background: rgba(255,255,255,.82);
        border: 1px solid #f0d8a3;
        color: #7a4d00;
        padding: 6px 9px;
        font-size: 11px;
        font-weight: 850;
    }

    @media (max-width: 1100px) {
        .editor-hero-grid,
        .editor-layout {
            grid-template-columns: 1fr;
        }

        .editor-side-card {
            position: static;
        }
    }

    @media (max-width: 768px) {
        .editor-hero,
        .form-section,
        .submit-strip,
        .editor-side-card {
            padding: 18px;
        }

        .type-grid,
        .health-grid {
            grid-template-columns: 1fr;
        }

        .hero-actions,
        .form-actions {
            flex-direction: column;
        }

        .partner-btn-primary,
        .partner-btn-outline,
        .partner-btn-soft {
            width: 100%;
        }
    }
</style>

<div class="partner-job-editor">
    <div class="editor-hero fade-in">
        <div class="editor-hero-grid">
            <div>
                <span class="partner-kicker">Job Post Editor</span>
                <div class="editor-title">Update this job post and keep candidate matches accurate.</div>
                <div class="editor-text">
                    Refine the title, requirements, location, and hiring details. Clearer job information helps alumni understand the role and helps the AI Top 5 matching feature recommend better candidates.
                </div>

                <div class="hero-actions">
                    <a href="job_posts.php" class="partner-btn-outline">Back to Job Posts</a>
                    <a data-loader-text="Analyzing candidates with AI… finding the top 5 matches for this job." href="top_matches.php?job_id=<?= (int) $job_id ?>" class="partner-btn-soft">AI Top 5 Matches</a>
                    <a href="applicants.php?job_id=<?= (int) $job_id ?>" class="partner-btn-soft">View Applicants</a>
                </div>
            </div>

            <div class="job-health-card">
                <div class="health-title">Job Post Pulse</div>
                <div class="health-grid">
                    <div class="health-item">
                        <div class="health-value"><?= (int) $applicantCount ?></div>
                        <div class="health-label">Applicants</div>
                    </div>
                    <div class="health-item">
                        <div class="health-value"><?= (int) $totalRecommendations ?></div>
                        <div class="health-label">Recommended Alumni</div>
                    </div>
                    <div class="health-item">
                        <div class="health-value"><?= (int) $bestMatchScore ?>%</div>
                        <div class="health-label">Top Match Score</div>
                    </div>
                </div>
                <div class="input-hint mt-3">
                    Updating requirements can improve future AI match quality. Refresh matches from the AI Top 5 page when needed.
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-partner mb-3">
            <strong>Please review the following:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="jobEditorForm">
        <div class="editor-layout">
            <div class="editor-card">
                <div class="form-section">
                    <div class="section-heading">
                        <div>
                            <h3>Job Overview</h3>
                            <p>Keep the main hiring details clear and searchable for alumni applicants.</p>
                        </div>
                        <div class="section-number">1</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Job Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="jobTitle" class="form-control" value="<?= htmlspecialchars($postedTitle) ?>" placeholder="e.g. Junior Web Developer" required>
                            <div class="input-hint">Use a specific title because it affects search, applications, and AI recommendations.</div>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" id="jobLocation" class="form-control" value="<?= htmlspecialchars($postedLocation) ?>" placeholder="e.g. Tagum City, Davao del Norte or Remote">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="jobStatus" class="form-select">
                                <option value="Open" <?= ($postedStatus === 'Open') ? 'selected' : '' ?>>Open</option>
                                <option value="Closed" <?= ($postedStatus === 'Closed') ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Employment Type</label>
                            <div class="type-grid">
                                <?php foreach (['Full-time', 'Part-time', 'Internship', 'Contract'] as $type): ?>
                                    <label class="type-option">
                                        <input type="radio" name="employment_type" value="<?= htmlspecialchars($type) ?>" <?= ($postedEmploymentType === $type) ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($type) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-heading">
                        <div>
                            <h3>Compensation and Deadline</h3>
                            <p>Update salary and deadline details so applicants know what to expect.</p>
                        </div>
                        <div class="section-number">2</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Salary Range</label>
                            <input type="text" name="salary_range" id="jobSalary" class="form-control" value="<?= htmlspecialchars($postedSalaryRange) ?>" placeholder="e.g. PHP 15,000 - PHP 20,000">
                            <div class="input-hint">You may also use “Negotiable” if salary is not fixed.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Application Deadline</label>
                            <input type="date" name="deadline" id="jobDeadline" class="form-control" value="<?= htmlspecialchars($postedDeadline) ?>">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-heading">
                        <div>
                            <h3>Job Description</h3>
                            <p>Explain the role, responsibilities, schedule, work environment, and expected output.</p>
                        </div>
                        <div class="section-number">3</div>
                    </div>

                    <label class="form-label">Job Description <span class="text-danger">*</span></label>
                    <textarea name="description" id="jobDescription" class="form-control" rows="7" required placeholder="Describe the role, responsibilities, and work setup."><?= htmlspecialchars($postedDescription) ?></textarea>
                    <div class="input-hint">Tip: Include daily tasks, team setup, tools used, and expected output.</div>
                </div>

                <div class="form-section">
                    <div class="section-heading">
                        <div>
                            <h3>Candidate Requirements</h3>
                            <p>Add skills, preferred courses, experience, documents, and qualifications needed for the role.</p>
                        </div>
                        <div class="section-number">4</div>
                    </div>

                    <label class="form-label">Requirements <span class="text-danger">*</span></label>
                    <textarea name="requirements" id="jobRequirements" class="form-control" rows="7" required placeholder="Example: BSIT graduate preferred, basic PHP/MySQL knowledge, communication skills, resume required."><?= htmlspecialchars($postedRequirements) ?></textarea>
                    <div class="input-hint">AI matching uses this section heavily. Add specific skills and course preferences when possible.</div>
                </div>

                <div class="submit-strip">
                    <div class="submit-note">
                        Required fields are marked with <span class="text-danger">*</span>. Saving changes updates the job post for alumni applicants.
                    </div>
                    <div class="form-actions mt-0">
                        <a href="job_posts.php" class="partner-btn-outline">Cancel</a>
                        <button type="submit" class="partner-btn-primary">Save Changes</button>
                    </div>
                </div>
            </div>

            <aside class="editor-side-card">
                <h3 class="tips-title">Editing Checklist</h3>
                <ul class="tip-list">
                    <li><strong>Improve match quality</strong>Add specific tools, skills, preferred course, and experience level.</li>
                    <li><strong>Keep applicants informed</strong>Update deadline, location, and salary when hiring details change.</li>
                    <li><strong>Manage status</strong>Set the job to Closed when you no longer accept applications.</li>
                    <li><strong>Review matches</strong>Open AI Top 5 Matches after saving major requirement changes.</li>
                </ul>

                <div class="preview-card">
                    <div class="preview-label">Live Preview</div>
                    <div class="preview-title" id="previewTitle"><?= htmlspecialchars($postedTitle !== '' ? $postedTitle : 'Untitled Job Post') ?></div>
                    <div class="preview-meta">
                        <span class="preview-chip" id="previewType"><?= htmlspecialchars($postedEmploymentType) ?></span>
                        <span class="preview-chip" id="previewLocation"><?= htmlspecialchars($postedLocation !== '' ? $postedLocation : 'Location not set') ?></span>
                        <span class="preview-chip" id="previewStatus"><?= htmlspecialchars($postedStatus) ?></span>
                    </div>
                </div>
            </aside>
        </div>
    </form>
</div>

<script>
    (function () {
        const titleInput = document.getElementById('jobTitle');
        const locationInput = document.getElementById('jobLocation');
        const statusInput = document.getElementById('jobStatus');
        const typeInputs = document.querySelectorAll('input[name="employment_type"]');

        const previewTitle = document.getElementById('previewTitle');
        const previewLocation = document.getElementById('previewLocation');
        const previewStatus = document.getElementById('previewStatus');
        const previewType = document.getElementById('previewType');

        function updatePreview() {
            previewTitle.textContent = titleInput.value.trim() || 'Untitled Job Post';
            previewLocation.textContent = locationInput.value.trim() || 'Location not set';
            previewStatus.textContent = statusInput.value || 'Open';

            let selectedType = 'Full-time';
            typeInputs.forEach(input => {
                if (input.checked) {
                    selectedType = input.value;
                }
            });
            previewType.textContent = selectedType;
        }

        [titleInput, locationInput, statusInput].forEach(input => {
            input.addEventListener('input', updatePreview);
            input.addEventListener('change', updatePreview);
        });

        typeInputs.forEach(input => input.addEventListener('change', updatePreview));
    })();
</script>

<?php require_once '../includes/dashboard_footer.php'; ?>
