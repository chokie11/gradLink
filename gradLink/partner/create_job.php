<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['partner']);

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
        $stmt = $pdo->prepare("
            INSERT INTO job_posts (
                partner_id, title, description, requirements, location,
                employment_type, salary_range, deadline, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $_SESSION['user_id'],
            $title,
            $description,
            $requirements,
            $location !== '' ? $location : null,
            $employment_type,
            $salary_range !== '' ? $salary_range : null,
            $deadline !== '' ? $deadline : null,
            $status
        ]);

        header('Location: job_posts.php');
        exit;
    }
}

$postedTitle = $_POST['title'] ?? '';
$postedDescription = $_POST['description'] ?? '';
$postedRequirements = $_POST['requirements'] ?? '';
$postedLocation = $_POST['location'] ?? '';
$postedEmploymentType = $_POST['employment_type'] ?? 'Full-time';
$postedSalaryRange = $_POST['salary_range'] ?? '';
$postedDeadline = $_POST['deadline'] ?? '';
$postedStatus = $_POST['status'] ?? 'Open';

$pageTitle = 'Create Job Post';
$pageSubtitle = 'Build a clear hiring post and improve AI-matched alumni recommendations.';
$activePage = 'jobs';

require_once '../includes/dashboard_header.php';
?>

<style>
    .partner-job-builder {
        --partner-gold: var(--maroon, #d08c0c);
        --partner-gold-dark: var(--dark-maroon, #be7f09);
        --partner-gold-soft: var(--soft-maroon, #e59d18);
        --partner-ink: #2c220f;
        --partner-muted: #7c6b4f;
        --partner-line: #f0d8a3;
        --partner-bg: #fffaf0;
    }

    .partner-job-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 30px;
        color: #2c220f;
        background:
            radial-gradient(circle at top right, rgba(229, 157, 24, .34), transparent 34%),
            linear-gradient(135deg, #ffffff 0%, #fff7e4 45%, #ffefc2 100%);
        border: 1px solid rgba(240, 216, 163, .95);
        box-shadow: 0 22px 55px rgba(133, 91, 10, .10);
        margin-bottom: 22px;
    }

    .partner-job-hero::after {
        content: '';
        position: absolute;
        right: -70px;
        bottom: -90px;
        width: 230px;
        height: 230px;
        border-radius: 50%;
        background: rgba(208, 140, 12, .12);
    }

    .partner-job-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1.5fr) minmax(280px, .85fr);
        gap: 24px;
        align-items: center;
    }

    .partner-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, .78);
        color: #8b5a02;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        border: 1px solid rgba(240, 216, 163, .95);
    }

    .partner-job-title {
        margin-top: 14px;
        font-size: clamp(28px, 4vw, 44px);
        line-height: 1.02;
        font-weight: 900;
        letter-spacing: -.04em;
        color: #2c220f;
    }

    .partner-job-text {
        margin-top: 12px;
        color: #6f5b37;
        font-size: 15px;
        line-height: 1.7;
        max-width: 720px;
    }

    .partner-hero-actions {
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
        font-weight: 800;
        text-decoration: none;
        border: 1px solid transparent;
        transition: all .18s ease;
    }

    .partner-btn-primary {
        background: linear-gradient(135deg, var(--partner-gold), var(--partner-gold-dark));
        color: #fff !important;
        box-shadow: 0 14px 28px rgba(208, 140, 12, .24);
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
        background: #fff3d1;
        color: #8b5a02 !important;
        border-color: #f3d99f;
    }

    .partner-job-scorecard {
        border-radius: 24px;
        background: rgba(255,255,255,.76);
        border: 1px solid rgba(240, 216, 163, .95);
        padding: 22px;
        box-shadow: 0 18px 36px rgba(133, 91, 10, .08);
    }

    .score-eyebrow {
        color: #8b5a02;
        font-weight: 900;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
    }

    .score-title {
        margin-top: 8px;
        font-size: 20px;
        font-weight: 900;
        color: #2c220f;
    }

    .score-list {
        margin: 14px 0 0;
        padding: 0;
        list-style: none;
        display: grid;
        gap: 10px;
    }

    .score-list li {
        display: flex;
        gap: 9px;
        align-items: flex-start;
        color: #6f5b37;
        font-size: 13px;
        line-height: 1.45;
    }

    .score-list li span {
        width: 24px;
        height: 24px;
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 24px;
        color: #fff;
        background: linear-gradient(135deg, var(--partner-gold), var(--partner-gold-dark));
        font-size: 12px;
        font-weight: 900;
    }

    .builder-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.45fr) minmax(300px, .75fr);
        gap: 22px;
        align-items: start;
    }

    .builder-card,
    .builder-side-card {
        background: #fff;
        border: 1px solid rgba(240, 216, 163, .9);
        border-radius: 24px;
        box-shadow: 0 18px 42px rgba(133, 91, 10, .08);
    }

    .builder-card {
        padding: 24px;
    }

    .builder-side-card {
        padding: 20px;
        position: sticky;
        top: 92px;
    }

    .form-section {
        padding: 20px;
        border-radius: 22px;
        background: linear-gradient(180deg, #ffffff 0%, #fffaf0 100%);
        border: 1px solid #f3dfb2;
        margin-bottom: 18px;
    }

    .form-section:last-child {
        margin-bottom: 0;
    }

    .section-heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 16px;
    }

    .section-heading h3 {
        margin: 0;
        font-size: 17px;
        font-weight: 900;
        color: #2c220f;
    }

    .section-heading p {
        margin: 5px 0 0;
        color: #7c6b4f;
        font-size: 13px;
        line-height: 1.5;
    }

    .section-number {
        width: 38px;
        height: 38px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, var(--partner-gold), var(--partner-gold-dark));
        color: #fff;
        font-weight: 900;
        flex: 0 0 38px;
        box-shadow: 0 12px 22px rgba(208, 140, 12, .22);
    }

    .partner-job-builder .form-label {
        color: #4f3c1c;
        font-weight: 800;
        font-size: 13px;
        margin-bottom: 8px;
    }

    .partner-job-builder .form-control,
    .partner-job-builder .form-select {
        border-radius: 14px;
        border: 1px solid #ead5a5;
        min-height: 46px;
        color: #2c220f;
        background-color: #fff;
        box-shadow: none;
    }

    .partner-job-builder textarea.form-control {
        min-height: 138px;
        resize: vertical;
    }

    .partner-job-builder .form-control:focus,
    .partner-job-builder .form-select:focus {
        border-color: var(--partner-gold);
        box-shadow: 0 0 0 .22rem rgba(208, 140, 12, .14);
    }

    .input-hint {
        color: #9a8255;
        font-size: 12px;
        margin-top: 7px;
    }

    .mini-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .type-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
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
        min-height: 48px;
        border-radius: 14px;
        border: 1px solid #ead5a5;
        background: #fff;
        color: #5f4a25;
        font-weight: 800;
        cursor: pointer;
        transition: all .18s ease;
        text-align: center;
        padding: 10px;
    }

    .type-option input:checked + span {
        background: linear-gradient(135deg, var(--partner-gold), var(--partner-gold-dark));
        color: #fff;
        border-color: var(--partner-gold-dark);
        box-shadow: 0 12px 24px rgba(208, 140, 12, .22);
    }

    .tips-title {
        color: #2c220f;
        font-weight: 900;
        font-size: 16px;
        margin: 0 0 12px;
    }

    .tip-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 12px;
    }

    .tip-list li {
        padding: 13px;
        border-radius: 16px;
        background: #fff8e8;
        border: 1px solid #f1d9a4;
        color: #6f5b37;
        font-size: 13px;
        line-height: 1.5;
    }

    .tip-list strong {
        display: block;
        color: #8b5a02;
        margin-bottom: 3px;
    }

    .preview-card {
        margin-top: 16px;
        padding: 15px;
        border-radius: 18px;
        border: 1px solid #f1d9a4;
        background: linear-gradient(135deg, #fff 0%, #fff8e8 100%);
    }

    .preview-card .preview-label {
        color: #8b5a02;
        text-transform: uppercase;
        letter-spacing: .08em;
        font-size: 11px;
        font-weight: 900;
        margin-bottom: 8px;
    }

    .preview-card .preview-title {
        font-size: 18px;
        font-weight: 900;
        color: #2c220f;
        margin-bottom: 8px;
    }

    .preview-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .preview-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 10px;
        border-radius: 999px;
        background: #fff1cf;
        color: #805100;
        font-size: 12px;
        font-weight: 800;
        border: 1px solid #efd59b;
    }

    .builder-submit-area {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding-top: 16px;
    }

    .submit-note {
        color: #7c6b4f;
        font-size: 13px;
    }

    .alert-partner {
        border-radius: 18px;
        border: 1px solid #f0b6aa;
        background: #fff3f1;
        color: #7f2417;
    }

    @media (max-width: 1080px) {
        .partner-job-hero-grid,
        .builder-layout {
            grid-template-columns: 1fr;
        }

        .builder-side-card {
            position: static;
        }
    }

    @media (max-width: 768px) {
        .partner-job-hero,
        .builder-card {
            padding: 20px;
            border-radius: 22px;
        }

        .type-grid,
        .mini-grid {
            grid-template-columns: 1fr;
        }

        .builder-submit-area {
            align-items: stretch;
        }

        .builder-submit-area .partner-btn-primary,
        .builder-submit-area .partner-btn-outline {
            width: 100%;
        }
    }
</style>

<div class="partner-job-builder">
    <div class="partner-job-hero fade-in">
        <div class="partner-job-hero-grid">
            <div>
                <span class="partner-kicker">Hiring Position Builder</span>
                <div class="partner-job-title">Create a job post that attracts the right alumni.</div>
                <div class="partner-job-text">
                    Add clear job details, requirements, and hiring expectations. Better information helps alumni understand the role and improves AI-assisted matching for your top candidate recommendations.
                </div>

                <div class="partner-hero-actions">
                    <a href="job_posts.php" class="partner-btn-outline">Back to Job Posts</a>
                    <a href="applicants.php" class="partner-btn-soft">View Applicants</a>
                </div>
            </div>

            <div class="partner-job-scorecard">
                <div class="score-eyebrow">AI Matching Guide</div>
                <div class="score-title">Better posts create better matches.</div>
                <ul class="score-list">
                    <li><span>1</span><div>Use a specific job title like “Junior Web Developer” instead of only “Staff”.</div></li>
                    <li><span>2</span><div>Add tools, skills, and course-related requirements for stronger alumni matching.</div></li>
                    <li><span>3</span><div>Include location, schedule, and salary range when possible to help applicants decide faster.</div></li>
                </ul>
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

    <form method="POST" action="" id="jobBuilderForm">
        <div class="builder-layout">
            <div class="builder-card">
                <div class="form-section">
                    <div class="section-heading">
                        <div>
                            <h3>Job Overview</h3>
                            <p>Start with the basic hiring details alumni will see first.</p>
                        </div>
                        <div class="section-number">1</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Job Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="jobTitle" class="form-control" value="<?= htmlspecialchars($postedTitle) ?>" placeholder="e.g. Junior Web Developer" required>
                            <div class="input-hint">Use a clear title because it affects search, applications, and AI recommendations.</div>
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
                            <p>These fields are optional, but they make your post more complete and easier to evaluate.</p>
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
                            <input type="date" name="deadline" id="jobDeadline" class="form-control" value="<?= htmlspecialchars($postedDeadline) ?>" min="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-heading">
                        <div>
                            <h3>Job Description</h3>
                            <p>Explain the role, responsibilities, schedule, work environment, and what the selected alumni will do.</p>
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

                <div class="builder-submit-area">
                    <div class="submit-note">
                        Required fields are marked with <span class="text-danger">*</span>. You can edit the job post later.
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="job_posts.php" class="partner-btn-outline">Cancel</a>
                        <button type="submit" class="partner-btn-primary">Create Job Post</button>
                    </div>
                </div>
            </div>

            <aside class="builder-side-card">
                <h3 class="tips-title">Posting Quality Checklist</h3>
                <ul class="tip-list">
                    <li><strong>Specific title</strong>Use a title alumni can recognize and search for.</li>
                    <li><strong>Clear skills</strong>Mention tools, technical skills, soft skills, and preferred course.</li>
                    <li><strong>Complete details</strong>Add location, salary, deadline, and employment type when available.</li>
                    <li><strong>Better matching</strong>More specific requirements help the system find better alumni candidates.</li>
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
