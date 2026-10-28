<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['partner']);

$user_id = (int) $_SESSION['user_id'];
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');

function pjShortText($text, $limit = 210)
{
    $text = trim((string) $text);
    if ($text === '') {
        return 'No description provided.';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function pjDeadlineLabel($deadline)
{
    if (empty($deadline)) {
        return ['No deadline', 'deadline-neutral'];
    }

    $today = new DateTime('today');
    $deadlineDate = new DateTime($deadline);
    $daysLeft = (int) $today->diff($deadlineDate)->format('%r%a');

    if ($daysLeft < 0) {
        return ['Expired', 'deadline-danger'];
    }

    if ($daysLeft === 0) {
        return ['Deadline today', 'deadline-warning'];
    }

    if ($daysLeft <= 7) {
        return [$daysLeft . ' days left', 'deadline-warning'];
    }

    return ['Due ' . date('M j, Y', strtotime($deadline)), 'deadline-good'];
}

function pjStatusClass($status)
{
    return $status === 'Open' ? 'status-open' : 'status-closed';
}

$sql = "\n    SELECT \n        jp.*,\n        COUNT(DISTINCT a.id) AS applicant_count,\n        COALESCE(MAX(jr.match_score), 0) AS best_saved_match\n    FROM job_posts jp\n    LEFT JOIN applications a ON jp.id = a.job_post_id\n    LEFT JOIN job_recommendations jr ON jp.id = jr.job_post_id\n    WHERE jp.partner_id = ?\n";
$params = [$user_id];

if ($search !== '') {
    $sql .= "\n        AND (\n            jp.title LIKE ?\n            OR jp.description LIKE ?\n            OR jp.requirements LIKE ?\n            OR jp.location LIKE ?\n            OR jp.employment_type LIKE ?\n        )\n    ";
    $term = '%' . $search . '%';
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

if ($statusFilter !== '' && in_array($statusFilter, ['Open', 'Closed'], true)) {
    $sql .= " AND jp.status = ?";
    $params[] = $statusFilter;
}

if ($typeFilter !== '' && in_array($typeFilter, ['Full-time', 'Part-time', 'Internship', 'Contract'], true)) {
    $sql .= " AND jp.employment_type = ?";
    $params[] = $typeFilter;
}

$sql .= "\n    GROUP BY jp.id\n    ORDER BY jp.created_at DESC\n";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$jobPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allStatsStmt = $pdo->prepare("\n    SELECT \n        COUNT(*) AS total_jobs,\n        SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) AS open_jobs,\n        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) AS closed_jobs,\n        SUM(CASE WHEN deadline IS NOT NULL AND deadline < CURDATE() THEN 1 ELSE 0 END) AS expired_jobs\n    FROM job_posts\n    WHERE partner_id = ?\n");
$allStatsStmt->execute([$user_id]);
$stats = $allStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$applicantTotalStmt = $pdo->prepare("\n    SELECT COUNT(*)\n    FROM applications a\n    INNER JOIN job_posts jp ON a.job_post_id = jp.id\n    WHERE jp.partner_id = ?\n");
$applicantTotalStmt->execute([$user_id]);
$totalApplicants = (int) $applicantTotalStmt->fetchColumn();

$bestMatchStmt = $pdo->prepare("\n    SELECT COALESCE(MAX(jr.match_score), 0)\n    FROM job_recommendations jr\n    INNER JOIN job_posts jp ON jr.job_post_id = jp.id\n    WHERE jp.partner_id = ?\n");
$bestMatchStmt->execute([$user_id]);
$bestMatchScore = (int) $bestMatchStmt->fetchColumn();

$pageTitle = 'Job Posts';
$pageSubtitle = 'Manage job openings and review AI-matched alumni candidates.';
$activePage = 'jobs';

require_once '../includes/dashboard_header.php';
?>

<style>
    .partner-console-hero {
        background: linear-gradient(135deg, #ffffff 0%, #fff7f7 48%, #f7eeee 100%);
        border: 1px solid #efdada;
        border-radius: 28px;
        padding: 28px;
        margin-bottom: 24px;
        box-shadow: 0 24px 55px rgba(100, 0, 0, .08);
        position: relative;
        overflow: hidden;
    }

    .partner-console-hero::before {
        content: '';
        position: absolute;
        right: -70px;
        top: -80px;
        width: 260px;
        height: 260px;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(123,0,0,.18), transparent 68%);
    }

    .partner-console-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #7b0000;
        background: #fff;
        border: 1px solid #ecd2d2;
        border-radius: 999px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 900;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .partner-console-title {
        color: #281616;
        font-size: clamp(28px, 4vw, 42px);
        font-weight: 950;
        letter-spacing: -.04em;
        line-height: 1.05;
        margin: 15px 0 10px;
    }

    .partner-console-text {
        color: #756060;
        max-width: 720px;
        line-height: 1.7;
        font-size: 15px;
    }

    .partner-console-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 20px;
    }

    .partner-primary-btn,
    .partner-secondary-btn {
        border-radius: 14px;
        padding: 11px 16px;
        font-weight: 850;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .partner-primary-btn {
        background: #7b0000;
        color: #fff;
        box-shadow: 0 16px 30px rgba(123,0,0,.18);
    }

    .partner-secondary-btn {
        background: #fff;
        color: #7b0000;
        border: 1px solid #ecd2d2;
    }

    .partner-stat-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .partner-stat-card {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 16px 38px rgba(100,0,0,.07);
    }

    .partner-stat-label {
        color: #806c6c;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .08em;
    }

    .partner-stat-value {
        color: #2d1515;
        font-size: 30px;
        line-height: 1;
        font-weight: 950;
        margin-top: 9px;
    }

    .partner-stat-note {
        color: #8a7474;
        font-size: 13px;
        margin-top: 7px;
    }

    .partner-filter-card {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 24px;
        padding: 18px;
        box-shadow: 0 16px 38px rgba(100,0,0,.06);
        margin-bottom: 22px;
    }

    .job-grid {
        display: grid;
        gap: 16px;
    }

    .partner-job-card {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 24px;
        padding: 20px;
        box-shadow: 0 18px 44px rgba(100,0,0,.075);
        position: relative;
        overflow: hidden;
    }

    .partner-job-card::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        width: 7px;
        height: 100%;
        background: linear-gradient(180deg, #7b0000, #d04b4b);
    }

    .job-card-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 14px;
        flex-wrap: wrap;
    }

    .job-title-main {
        color: #2d1515;
        font-size: 20px;
        font-weight: 950;
        letter-spacing: -.02em;
        margin: 0;
    }

    .job-meta-line {
        color: #806c6c;
        font-size: 13px;
        margin-top: 6px;
        line-height: 1.6;
    }

    .job-badge-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }

    .job-pill {
        border-radius: 999px;
        padding: 7px 11px;
        font-size: 12px;
        font-weight: 850;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .status-open {
        color: #0f6e3c;
        background: #e9f8f0;
        border: 1px solid #c7ead6;
    }

    .status-closed {
        color: #595959;
        background: #f1f1f1;
        border: 1px solid #dfdfdf;
    }

    .deadline-good {
        color: #0f6e3c;
        background: #f0fbf5;
        border: 1px solid #cbeed9;
    }

    .deadline-warning {
        color: #8a5500;
        background: #fff8e8;
        border: 1px solid #f0dda8;
    }

    .deadline-danger {
        color: #a51d1d;
        background: #fff1f1;
        border: 1px solid #f0c9c9;
    }

    .deadline-neutral {
        color: #6d6060;
        background: #faf7f7;
        border: 1px solid #eadada;
    }

    .job-description-preview {
        color: #746161;
        line-height: 1.7;
        margin-top: 14px;
    }

    .job-insight-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        margin-top: 16px;
    }

    .job-insight-box {
        background: #fff8f8;
        border: 1px solid #f0dddd;
        border-radius: 16px;
        padding: 12px;
    }

    .job-insight-label {
        color: #8b7777;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .08em;
    }

    .job-insight-value {
        color: #2d1515;
        font-size: 18px;
        font-weight: 950;
        margin-top: 4px;
    }

    .job-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 9px;
        margin-top: 16px;
    }

    .job-action-primary,
    .job-action-secondary,
    .job-action-danger {
        border-radius: 13px;
        padding: 10px 13px;
        font-size: 13px;
        font-weight: 850;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .job-action-primary {
        background: #7b0000;
        color: #fff;
    }

    .job-action-secondary {
        background: #fff;
        color: #6b5353;
        border: 1px solid #ead7d7;
    }

    .job-action-danger {
        background: #fff5f5;
        color: #a51d1d;
        border: 1px solid #f0c9c9;
    }

    .empty-jobs-card {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 24px;
        padding: 36px;
        text-align: center;
        color: #806c6c;
        box-shadow: 0 18px 44px rgba(100,0,0,.07);
    }

    @media (max-width: 1100px) {
        .partner-stat-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .job-insight-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 576px) {
        .partner-console-hero {
            padding: 22px;
            border-radius: 22px;
        }

        .partner-stat-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="partner-console-hero">
    <div class="partner-console-kicker">Recruitment Operations</div>
    <div class="partner-console-title">Manage openings and discover matched alumni faster.</div>
    <div class="partner-console-text">
        Post opportunities, monitor applications, and use the AI matching filter to surface the top alumni candidates for each position.
    </div>
    <div class="partner-console-actions">
        <a href="create_job.php" class="partner-primary-btn">+ Create Job Post</a>
        <a href="applicants.php" class="partner-secondary-btn">Review Applicants</a>
    </div>
</div>

<div class="partner-stat-grid">
    <div class="partner-stat-card">
        <div class="partner-stat-label">Total Posts</div>
        <div class="partner-stat-value"><?= (int) ($stats['total_jobs'] ?? 0) ?></div>
        <div class="partner-stat-note">All job listings</div>
    </div>
    <div class="partner-stat-card">
        <div class="partner-stat-label">Open Jobs</div>
        <div class="partner-stat-value"><?= (int) ($stats['open_jobs'] ?? 0) ?></div>
        <div class="partner-stat-note">Accepting applicants</div>
    </div>
    <div class="partner-stat-card">
        <div class="partner-stat-label">Applicants</div>
        <div class="partner-stat-value"><?= (int) $totalApplicants ?></div>
        <div class="partner-stat-note">Submitted applications</div>
    </div>
    <div class="partner-stat-card">
        <div class="partner-stat-label">Best AI Match</div>
        <div class="partner-stat-value"><?= (int) $bestMatchScore ?>%</div>
        <div class="partner-stat-note">Saved recommendation score</div>
    </div>
    <div class="partner-stat-card">
        <div class="partner-stat-label">Expired</div>
        <div class="partner-stat-value"><?= (int) ($stats['expired_jobs'] ?? 0) ?></div>
        <div class="partner-stat-note">Past deadline</div>
    </div>
</div>

<div class="partner-filter-card">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-lg-5">
            <label class="form-label">Search Job</label>
            <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Search title, location, requirements, or type">
        </div>
        <div class="col-lg-2 col-md-4">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All Status</option>
                <option value="Open" <?= $statusFilter === 'Open' ? 'selected' : '' ?>>Open</option>
                <option value="Closed" <?= $statusFilter === 'Closed' ? 'selected' : '' ?>>Closed</option>
            </select>
        </div>
        <div class="col-lg-2 col-md-4">
            <label class="form-label">Type</label>
            <select name="type" class="form-select">
                <option value="">All Types</option>
                <option value="Full-time" <?= $typeFilter === 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                <option value="Part-time" <?= $typeFilter === 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                <option value="Internship" <?= $typeFilter === 'Internship' ? 'selected' : '' ?>>Internship</option>
                <option value="Contract" <?= $typeFilter === 'Contract' ? 'selected' : '' ?>>Contract</option>
            </select>
        </div>
        <div class="col-lg-3 col-md-4 d-flex gap-2">
            <button type="submit" class="partner-primary-btn border-0">Apply Filters</button>
            <a href="job_posts.php" class="partner-secondary-btn">Reset</a>
        </div>
    </form>
</div>

<?php if (empty($jobPosts)): ?>
    <div class="empty-jobs-card">
        <h4 class="fw-bold mb-2">No job posts found</h4>
        <div class="mb-3">Create your first job post so alumni can apply and the AI matcher can recommend suitable candidates.</div>
        <a href="create_job.php" class="partner-primary-btn">+ Create Job Post</a>
    </div>
<?php else: ?>
    <div class="job-grid">
        <?php foreach ($jobPosts as $job): ?>
            <?php [$deadlineText, $deadlineClass] = pjDeadlineLabel($job['deadline'] ?? null); ?>
            <div class="partner-job-card">
                <div class="job-card-head">
                    <div>
                        <h3 class="job-title-main"><?= htmlspecialchars($job['title']) ?></h3>
                        <div class="job-meta-line">
                            <?= htmlspecialchars($job['employment_type'] ?? 'Full-time') ?>
                            <?php if (!empty($job['location'])): ?> • <?= htmlspecialchars($job['location']) ?><?php endif; ?>
                            <?php if (!empty($job['salary_range'])): ?> • <?= htmlspecialchars($job['salary_range']) ?><?php endif; ?>
                        </div>
                    </div>

                    <div class="job-badge-row mt-0">
                        <span class="job-pill <?= htmlspecialchars(pjStatusClass($job['status'] ?? 'Open')) ?>">
                            <?= htmlspecialchars($job['status'] ?? 'Open') ?>
                        </span>
                        <span class="job-pill <?= htmlspecialchars($deadlineClass) ?>">
                            <?= htmlspecialchars($deadlineText) ?>
                        </span>
                    </div>
                </div>

                <div class="job-description-preview">
                    <?= nl2br(htmlspecialchars(pjShortText($job['description'] ?? ''))) ?>
                </div>

                <div class="job-insight-grid">
                    <div class="job-insight-box">
                        <div class="job-insight-label">Applicants</div>
                        <div class="job-insight-value"><?= (int) ($job['applicant_count'] ?? 0) ?></div>
                    </div>
                    <div class="job-insight-box">
                        <div class="job-insight-label">Best Saved Match</div>
                        <div class="job-insight-value"><?= (int) ($job['best_saved_match'] ?? 0) ?>%</div>
                    </div>
                    <div class="job-insight-box">
                        <div class="job-insight-label">Posted</div>
                        <div class="job-insight-value" style="font-size:16px;"><?= !empty($job['created_at']) ? date('M j, Y', strtotime($job['created_at'])) : '—' ?></div>
                    </div>
                </div>

                <div class="job-actions">
                    <a data-loader-text="Analyzing candidates with AI… finding the top 5 matches for this job." href="top_matches.php?job_id=<?= (int) $job['id'] ?>" class="job-action-primary">AI Top 5 Matches</a>
                    <a href="applicants.php?job_id=<?= (int) $job['id'] ?>" class="job-action-secondary">View Applicants</a>
                    <a href="edit_job.php?id=<?= (int) $job['id'] ?>" class="job-action-secondary">Edit</a>
                    <a href="delete_job.php?id=<?= (int) $job['id'] ?>" class="job-action-danger" onclick="return confirm('Are you sure you want to delete this job post?');">Delete</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once '../includes/dashboard_footer.php'; ?>
