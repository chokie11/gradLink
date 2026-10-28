<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$job_id = $_GET['id'] ?? null;

if (!$job_id || !ctype_digit($job_id)) {
    header('Location: recommended_jobs.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        jp.*,
        pp.company_name,
        pp.industry_type,
        pp.website,
        pp.company_description
    FROM job_posts jp
    INNER JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    WHERE jp.id = ?
      AND jp.status = 'Open'
    LIMIT 1
");
$stmt->execute([$job_id]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    header('Location: recommended_jobs.php');
    exit;
}

$pageTitle = 'Job Details';
$pageSubtitle = 'Review the full job opportunity details.';
$activePage = 'jobs';

$checkApplyStmt = $pdo->prepare("
    SELECT id
    FROM applications
    WHERE alumni_id = ? AND job_post_id = ?
    LIMIT 1
");
$checkApplyStmt->execute([$_SESSION['user_id'], $job_id]);
$alreadyApplied = $checkApplyStmt->fetch();

require_once '../includes/dashboard_header.php';
?>

<div class="hero-card">
    <div class="hero-grid">
        <div>
            <span class="badge-soft">Job Opportunity</span>
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
                <a href="recommended_jobs.php" class="btn-light-outline">Back to Jobs</a>
<?php if ($alreadyApplied): ?>
    <a href="applications.php" class="btn-maroon">Already Applied</a>
<?php else: ?>
    <a href="apply_job.php?id=<?= $job['id'] ?>" class="btn-maroon">Apply Now</a>
<?php endif; ?>            </div>
        </div>

        <div class="progress-card">
            <div class="label">Application Deadline</div>
            <div class="value" style="font-size:24px;">
                <?= !empty($job['deadline']) ? date('M j, Y', strtotime($job['deadline'])) : 'Open' ?>
            </div>
            <div class="small">
                <?= !empty($job['salary_range']) ? 'Salary: ' . htmlspecialchars($job['salary_range']) : 'Salary not specified' ?>
            </div>
        </div>
    </div>
</div>

<div class="content-grid">
    <div class="panel-card">
        <div class="panel-title">Job Description</div>
        <div class="text-muted" style="line-height:1.8;">
            <?= nl2br(htmlspecialchars($job['description'])) ?>
        </div>
    </div>

    <div class="panel-card">
        <div class="panel-title">Job Summary</div>
        <div class="info-list">
            <div class="info-item">
                <div class="info-key">Company</div>
                <div class="info-value"><?= htmlspecialchars($job['company_name'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="info-key">Industry</div>
                <div class="info-value"><?= htmlspecialchars($job['industry_type'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="info-key">Location</div>
                <div class="info-value"><?= htmlspecialchars($job['location'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="info-key">Employment Type</div>
                <div class="info-value"><?= htmlspecialchars($job['employment_type'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="info-key">Salary Range</div>
                <div class="info-value"><?= htmlspecialchars($job['salary_range'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="info-key">Deadline</div>
                <div class="info-value"><?= !empty($job['deadline']) ? date('F j, Y', strtotime($job['deadline'])) : '—' ?></div>
            </div>
        </div>
    </div>
</div>

<div class="panel-card mt-4">
    <div class="panel-title">Requirements</div>
    <div class="text-muted" style="line-height:1.8;">
        <?= nl2br(htmlspecialchars($job['requirements'])) ?>
    </div>
</div>

<?php if (!empty($job['company_description'])): ?>
    <div class="panel-card mt-4">
        <div class="panel-title">About the Company</div>
        <div class="text-muted" style="line-height:1.8;">
            <?= nl2br(htmlspecialchars($job['company_description'])) ?>
        </div>

        <?php if (!empty($job['website'])): ?>
            <div class="mt-3">
                <span class="badge-soft"><?= htmlspecialchars($job['website']) ?></span>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once '../includes/dashboard_footer.php'; ?>