<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['partner']);

$user_id = $_SESSION['user_id'];

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatStatusLabel($status) {
    $status = trim((string) $status);
    return $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Pending';
}

function statusClass($status) {
    $status = strtolower(trim((string) $status));
    if (in_array($status, ['hired', 'accepted', 'approved'], true)) {
        return 'success';
    }
    if (in_array($status, ['reviewed', 'shortlisted', 'interview'], true)) {
        return 'info';
    }
    if (in_array($status, ['rejected', 'declined'], true)) {
        return 'danger';
    }
    if (in_array($status, ['closed', 'expired'], true)) {
        return 'muted';
    }
    return 'warning';
}

$stmt = $pdo->prepare("\n    SELECT u.first_name, u.last_name, u.email, pp.*\n    FROM users u\n    LEFT JOIN partner_profiles pp ON u.id = pp.user_id\n    WHERE u.id = ?\n    LIMIT 1\n");
$stmt->execute([$user_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$requiredFields = [
    $profile['company_name'] ?? '',
    $profile['industry_type'] ?? '',
    $profile['contact_person'] ?? '',
    $profile['phone'] ?? '',
    $profile['address'] ?? ''
];

$filled = 0;
foreach ($requiredFields as $field) {
    if (!empty(trim((string) $field))) {
        $filled++;
    }
}
$completion = count($requiredFields) > 0 ? round(($filled / count($requiredFields)) * 100) : 0;

$stmt = $pdo->prepare("\n    SELECT *\n    FROM job_posts\n    WHERE partner_id = ?\n    ORDER BY created_at DESC\n");
$stmt->execute([$user_id]);
$jobPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("\n    SELECT \n        a.id AS application_id,\n        a.status,\n        a.applied_at,\n        a.resume_id,\n        a.applicant_name,\n        a.applicant_email,\n        a.applicant_phone,\n        a.applicant_address,\n        a.applicant_course,\n        a.applicant_major,\n        a.applicant_graduation_year,\n        a.applicant_employment_status,\n        a.applicant_skills,\n        a.applicant_summary,\n        jp.id AS job_post_id,\n        jp.title AS job_title,\n        r.file_name AS resume_file_name,\n        r.file_path AS resume_file_path\n    FROM applications a\n    INNER JOIN job_posts jp ON a.job_post_id = jp.id\n    LEFT JOIN resumes r ON a.resume_id = r.id\n    WHERE jp.partner_id = ?\n    ORDER BY a.applied_at DESC\n");
$stmt->execute([$user_id]);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalJobPosts = count($jobPosts);
$totalApplicants = count($applicants);
$openJobs = 0;
$closedJobs = 0;
$expiredJobs = 0;
$pendingApplicants = 0;
$reviewedApplicants = 0;
$hiredApplicants = 0;
$applicantsPerJob = [];

foreach ($jobPosts as $post) {
    $status = strtolower((string) ($post['status'] ?? ''));
    if ($status === 'open' || $status === 'active' || $status === 'published') {
        $openJobs++;
    }
    if ($status === 'closed' || $status === 'inactive') {
        $closedJobs++;
    }
    if (!empty($post['deadline']) && strtotime($post['deadline']) !== false && strtotime($post['deadline']) < strtotime(date('Y-m-d'))) {
        $expiredJobs++;
    }
}

foreach ($applicants as $applicant) {
    $status = strtolower((string) ($applicant['status'] ?? 'pending'));
    if ($status === '' || $status === 'pending') {
        $pendingApplicants++;
    }
    if (in_array($status, ['reviewed', 'shortlisted', 'interview'], true)) {
        $reviewedApplicants++;
    }
    if (in_array($status, ['hired', 'accepted', 'approved'], true)) {
        $hiredApplicants++;
    }

    $jobId = (int) ($applicant['job_post_id'] ?? 0);
    if ($jobId > 0) {
        $applicantsPerJob[$jobId] = ($applicantsPerJob[$jobId] ?? 0) + 1;
    }
}

$bestMatchScore = null;
$aiRecommendationsCount = 0;
try {
    $matchStmt = $pdo->prepare("\n        SELECT MAX(jr.match_score) AS best_score, COUNT(*) AS total_recommendations\n        FROM job_recommendations jr\n        INNER JOIN job_posts jp ON jr.job_post_id = jp.id\n        WHERE jp.partner_id = ?\n    ");
    $matchStmt->execute([$user_id]);
    $matchData = $matchStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($matchData && $matchData['best_score'] !== null) {
        $bestMatchScore = round((float) $matchData['best_score']);
    }
    $aiRecommendationsCount = (int) ($matchData['total_recommendations'] ?? 0);
} catch (Exception $e) {
    $bestMatchScore = null;
    $aiRecommendationsCount = 0;
}

$recentApplicants = array_slice($applicants, 0, 5);
$activeJobPosts = [];
foreach ($jobPosts as $post) {
    $status = strtolower((string) ($post['status'] ?? ''));
    if ($status === 'open' || $status === 'active' || $status === 'published') {
        $activeJobPosts[] = $post;
    }
}
if (empty($activeJobPosts)) {
    $activeJobPosts = array_slice($jobPosts, 0, 4);
} else {
    $activeJobPosts = array_slice($activeJobPosts, 0, 4);
}

$companyName = trim((string) ($profile['company_name'] ?? ''));
$displayCompanyName = $companyName !== '' ? $companyName : 'Your Company';

$pageTitle = 'Partner Dashboard';
$pageSubtitle = 'Recruitment command center for job posts, applicants, and AI-matched alumni.';
$activePage = 'dashboard';

require_once '../includes/dashboard_header.php';
?>

<style>
    .partner-dashboard-shell {
        --gold: #d08c0c;
        --gold-dark: #a96805;
        --gold-soft: #fff4d9;
        --gold-border: #f0d49c;
        --ink: #24190a;
        --muted: #806f57;
    }

    .recruit-hero {
        position: relative;
        overflow: hidden;
        border-radius: 30px;
        padding: 34px;
        background:
            radial-gradient(circle at 88% 10%, rgba(255, 193, 77, 0.42), transparent 30%),
            linear-gradient(135deg, #ffffff 0%, #fff8e9 58%, #fff0ce 100%);
        border: 1px solid rgba(240, 212, 156, 0.95);
        box-shadow: 0 22px 60px rgba(123, 78, 0, 0.10);
    }

    .recruit-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(208, 140, 12, 0.08) 1px, transparent 1px),
            linear-gradient(90deg, rgba(208, 140, 12, 0.08) 1px, transparent 1px);
        background-size: 34px 34px;
        mask-image: linear-gradient(90deg, transparent 0%, #000 35%, #000 100%);
        pointer-events: none;
    }

    .recruit-hero-grid {
        position: relative;
        display: grid;
        grid-template-columns: minmax(0, 1.55fr) 390px;
        gap: 28px;
        align-items: stretch;
        z-index: 1;
    }

    .recruit-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 14px;
        border-radius: 999px;
        background: #ffffff;
        border: 1px solid var(--gold-border);
        color: #8b5800;
        font-weight: 900;
        letter-spacing: 1.4px;
        text-transform: uppercase;
        font-size: 12px;
        box-shadow: 0 10px 24px rgba(126, 80, 0, 0.07);
    }

    .recruit-title {
        margin-top: 20px;
        font-size: clamp(34px, 4vw, 56px);
        line-height: 0.98;
        letter-spacing: -2px;
        font-weight: 950;
        color: var(--ink);
        max-width: 900px;
    }

    .recruit-text {
        margin-top: 18px;
        color: #705d45;
        font-size: 17px;
        line-height: 1.7;
        max-width: 830px;
    }

    .recruit-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 26px;
    }

    .partner-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        min-height: 48px;
        padding: 12px 18px;
        border-radius: 15px;
        font-weight: 900;
        text-decoration: none;
        border: 1px solid transparent;
        transition: 0.18s ease;
    }

    .partner-btn:hover {
        transform: translateY(-2px);
        text-decoration: none;
    }

    .partner-btn-primary {
        color: #ffffff;
        background: linear-gradient(135deg, #d08c0c, #b77506);
        box-shadow: 0 14px 30px rgba(208, 140, 12, 0.24);
    }

    .partner-btn-primary:hover {
        color: #ffffff;
        background: linear-gradient(135deg, #be7f09, #9e6505);
    }

    .partner-btn-outline {
        color: #8b5800;
        background: rgba(255, 255, 255, 0.86);
        border-color: var(--gold-border);
    }

    .partner-btn-outline:hover {
        color: #6f4600;
        background: #fff4d9;
    }

    .recruit-readiness-card {
        position: relative;
        padding: 24px;
        border-radius: 26px;
        background: rgba(255, 255, 255, 0.86);
        border: 1px solid rgba(240, 212, 156, 0.95);
        box-shadow: 0 20px 45px rgba(126, 80, 0, 0.09);
        backdrop-filter: blur(10px);
    }

    .readiness-topline {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        margin-bottom: 18px;
    }

    .readiness-label {
        color: #8a7350;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 12px;
    }

    .readiness-value {
        color: var(--ink);
        font-weight: 950;
        font-size: 46px;
        line-height: 1;
    }

    .readiness-badge {
        width: 52px;
        height: 52px;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        background: linear-gradient(135deg, #d08c0c, #a96805);
        font-size: 22px;
        box-shadow: 0 12px 28px rgba(208, 140, 12, 0.22);
    }

    .gold-progress-track {
        height: 12px;
        border-radius: 999px;
        background: #fff0ca;
        overflow: hidden;
        border: 1px solid #f3d9a4;
    }

    .gold-progress-bar {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #d08c0c, #f0ad24);
        box-shadow: 0 8px 20px rgba(208, 140, 12, 0.25);
    }

    .readiness-note {
        margin-top: 14px;
        color: #746348;
        font-size: 14px;
        line-height: 1.6;
    }

    .partner-stat-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 16px;
        margin: 22px 0 26px;
    }

    .partner-stat-card {
        position: relative;
        overflow: hidden;
        border-radius: 22px;
        padding: 20px 20px 18px;
        background: #ffffff;
        border: 1px solid rgba(240, 212, 156, 0.95);
        box-shadow: 0 16px 42px rgba(126, 80, 0, 0.07);
    }

    .partner-stat-card::after {
        content: '';
        position: absolute;
        right: -30px;
        top: -35px;
        width: 92px;
        height: 92px;
        border-radius: 50%;
        background: rgba(208, 140, 12, 0.10);
    }

    .partner-stat-label {
        color: #7c6b55;
        font-size: 12px;
        font-weight: 950;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .partner-stat-value {
        margin-top: 10px;
        color: var(--ink);
        font-size: 34px;
        font-weight: 950;
        line-height: 1;
    }

    .partner-stat-note {
        margin-top: 9px;
        color: #806f57;
        font-size: 14px;
    }

    .recruit-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(340px, 0.85fr);
        gap: 20px;
        align-items: start;
    }

    .recruit-panel {
        border-radius: 26px;
        background: #ffffff;
        border: 1px solid rgba(240, 212, 156, 0.95);
        box-shadow: 0 18px 45px rgba(126, 80, 0, 0.07);
        padding: 22px;
        margin-bottom: 20px;
    }

    .panel-heading-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 16px;
    }

    .panel-heading-title {
        color: var(--ink);
        font-size: 20px;
        font-weight: 950;
        letter-spacing: -0.3px;
    }

    .panel-heading-subtitle {
        color: #806f57;
        margin-top: 4px;
        font-size: 14px;
    }

    .small-link {
        color: #9a6405;
        font-weight: 900;
        font-size: 13px;
        text-decoration: none;
        white-space: nowrap;
    }

    .small-link:hover {
        color: #6f4600;
        text-decoration: underline;
    }

    .applicant-row,
    .job-row,
    .quick-row {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        padding: 15px 0;
        border-top: 1px solid #f3e5ca;
    }

    .applicant-row:first-of-type,
    .job-row:first-of-type,
    .quick-row:first-of-type {
        border-top: 0;
        padding-top: 2px;
    }

    .avatar-gold {
        width: 44px;
        height: 44px;
        border-radius: 15px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        font-weight: 950;
        background: linear-gradient(135deg, #d08c0c, #a96805);
        flex: 0 0 44px;
    }

    .candidate-info {
        display: flex;
        gap: 12px;
        min-width: 0;
    }

    .row-title {
        color: var(--ink);
        font-weight: 900;
        line-height: 1.25;
    }

    .row-meta {
        color: #806f57;
        font-size: 13px;
        margin-top: 4px;
        line-height: 1.45;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        padding: 7px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .status-pill.warning {
        color: #8a5700;
        background: #fff3cf;
        border-color: #efd28d;
    }

    .status-pill.info {
        color: #0f5f7c;
        background: #e9f8ff;
        border-color: #bde8f8;
    }

    .status-pill.success {
        color: #17613b;
        background: #eaf9f0;
        border-color: #bae7ca;
    }

    .status-pill.danger {
        color: #8a1f1f;
        background: #fff0f0;
        border-color: #f3c0c0;
    }

    .status-pill.muted {
        color: #6b645d;
        background: #f3f0ec;
        border-color: #ddd3c4;
    }

    .job-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
        margin-top: 10px;
    }

    .mini-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 34px;
        padding: 8px 11px;
        border-radius: 11px;
        font-weight: 900;
        font-size: 12px;
        text-decoration: none;
        border: 1px solid #f0d49c;
        color: #8b5800;
        background: #fff9ea;
    }

    .mini-btn:hover {
        color: #6f4600;
        background: #fff0ca;
        text-decoration: none;
    }

    .quick-row {
        align-items: center;
        text-decoration: none;
        color: inherit;
        border-radius: 16px;
        padding: 13px 12px;
        border: 1px solid #f3e5ca;
        margin-bottom: 10px;
        background: #fffdf8;
        transition: 0.18s ease;
    }

    .quick-row:hover {
        transform: translateX(3px);
        background: #fff4d9;
        border-color: #e9c371;
        text-decoration: none;
        color: inherit;
    }

    .quick-icon {
        width: 40px;
        height: 40px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fff0ca;
        color: #9a6405;
        font-weight: 950;
        flex: 0 0 40px;
    }

    .empty-panel {
        padding: 26px;
        border-radius: 18px;
        background: #fffaf0;
        border: 1px dashed #e6c77f;
        color: #806f57;
        text-align: center;
    }

    .company-strip {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-top: 12px;
    }

    .company-chip {
        padding: 12px;
        border-radius: 16px;
        background: #fffaf0;
        border: 1px solid #f3e5ca;
    }

    .company-chip span {
        display: block;
        color: #8a7350;
        font-size: 11px;
        font-weight: 900;
        letter-spacing: 0.8px;
        text-transform: uppercase;
    }

    .company-chip strong {
        display: block;
        margin-top: 4px;
        color: var(--ink);
        font-weight: 900;
        word-break: break-word;
    }

    @media (max-width: 1200px) {
        .recruit-hero-grid,
        .recruit-layout {
            grid-template-columns: 1fr;
        }

        .partner-stat-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 768px) {
        .recruit-hero {
            padding: 24px;
            border-radius: 24px;
        }

        .partner-stat-grid {
            grid-template-columns: 1fr;
        }

        .applicant-row,
        .job-row {
            flex-direction: column;
        }

        .job-actions {
            justify-content: flex-start;
        }

        .company-strip {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="partner-dashboard-shell">
    <section class="recruit-hero fade-in">
        <div class="recruit-hero-grid">
            <div>
                <span class="recruit-kicker">Recruitment Hub</span>
                <div class="recruit-title">Find qualified alumni candidates faster.</div>
                <div class="recruit-text">
                    Manage job openings, review applicants, and use AI-assisted matching to discover the most suitable alumni for your hiring needs.
                </div>

                <div class="recruit-actions">
                    <a href="create_job.php" class="partner-btn partner-btn-primary">+ Create Job Post</a>
                    <a href="job_posts.php" class="partner-btn partner-btn-outline">Manage Job Posts</a>
                    <a href="applicants.php" class="partner-btn partner-btn-outline">Review Applicants</a>
                </div>
            </div>

            <div class="recruit-readiness-card">
                <div class="readiness-topline">
                    <div>
                        <div class="readiness-label">Company Readiness</div>
                        <div class="readiness-value"><?= $completion ?>%</div>
                    </div>
                    <div class="readiness-badge">↗</div>
                </div>

                <div class="gold-progress-track">
                    <div class="gold-progress-bar" style="width: <?= $completion ?>%"></div>
                </div>

                <div class="readiness-note">
                    <?= $completion < 100 ? 'Complete your company profile to build trust with alumni applicants.' : 'Your company profile is complete and ready for recruitment activity.' ?>
                </div>

                <div class="company-strip">
                    <div class="company-chip">
                        <span>Company</span>
                        <strong><?= h($displayCompanyName) ?></strong>
                    </div>
                    <div class="company-chip">
                        <span>Industry</span>
                        <strong><?= h($profile['industry_type'] ?? '—') ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="partner-stat-grid fade-in">
        <div class="partner-stat-card">
            <div class="partner-stat-label">Open Jobs</div>
            <div class="partner-stat-value"><?= $openJobs ?></div>
            <div class="partner-stat-note">Accepting applicants</div>
        </div>

        <div class="partner-stat-card">
            <div class="partner-stat-label">Total Applicants</div>
            <div class="partner-stat-value"><?= $totalApplicants ?></div>
            <div class="partner-stat-note">Across all job posts</div>
        </div>

        <div class="partner-stat-card">
            <div class="partner-stat-label">Pending Review</div>
            <div class="partner-stat-value"><?= $pendingApplicants ?></div>
            <div class="partner-stat-note">Need screening</div>
        </div>

        <div class="partner-stat-card">
            <div class="partner-stat-label">Recommended Alumni</div>
            <div class="partner-stat-value"><?= $aiRecommendationsCount ?></div>
            <div class="partner-stat-note">AI-matched candidate suggestions</div>
        </div>

        <div class="partner-stat-card">
            <div class="partner-stat-label">Top Match Score</div>
            <div class="partner-stat-value"><?= $bestMatchScore !== null ? $bestMatchScore . '%' : '—' ?></div>
            <div class="partner-stat-note">Highest candidate-job fit</div>
        </div>
    </section>

    <section class="recruit-layout fade-in">
        <div>
            <div class="recruit-panel">
                <div class="panel-heading-row">
                    <div>
                        <div class="panel-heading-title">Recent Applicants</div>
                        <div class="panel-heading-subtitle">Latest alumni who submitted applications to your job posts.</div>
                    </div>
                    <a href="applicants.php" class="small-link">View all →</a>
                </div>

                <?php if (empty($recentApplicants)): ?>
                    <div class="empty-panel">
                        No applicants yet. Once alumni apply to your job posts, they will appear here.
                    </div>
                <?php else: ?>
                    <?php foreach ($recentApplicants as $applicant): ?>
                        <?php
                            $initialSource = trim((string) ($applicant['applicant_name'] ?? 'A'));
                            $initial = strtoupper(substr($initialSource !== '' ? $initialSource : 'A', 0, 1));
                            $appliedDate = !empty($applicant['applied_at']) ? date('M j, Y', strtotime($applicant['applied_at'])) : '—';
                        ?>
                        <div class="applicant-row">
                            <div class="candidate-info">
                                <div class="avatar-gold"><?= h($initial) ?></div>
                                <div>
                                    <div class="row-title"><?= h($applicant['applicant_name'] ?? 'Unnamed Applicant') ?></div>
                                    <div class="row-meta">
                                        <?= h($applicant['job_title'] ?? 'Job Post') ?>
                                        <?= !empty($applicant['applicant_course']) ? ' • ' . h($applicant['applicant_course']) : '' ?>
                                        <?= !empty($applicant['applicant_graduation_year']) ? ' • Batch ' . h($applicant['applicant_graduation_year']) : '' ?>
                                    </div>
                                    <div class="row-meta">Applied <?= h($appliedDate) ?></div>
                                </div>
                            </div>

                            <div>
                                <span class="status-pill <?= statusClass($applicant['status'] ?? 'pending') ?>">
                                    <?= h(formatStatusLabel($applicant['status'] ?? 'pending')) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="recruit-panel">
                <div class="panel-heading-row">
                    <div>
                        <div class="panel-heading-title">Active Job Posts</div>
                        <div class="panel-heading-subtitle">Monitor open roles and access AI-matched alumni candidates.</div>
                    </div>
                    <a href="job_posts.php" class="small-link">Manage posts →</a>
                </div>

                <?php if (empty($activeJobPosts)): ?>
                    <div class="empty-panel">
                        No active job posts yet. Create a job post to start receiving applicants and AI matches.
                    </div>
                <?php else: ?>
                    <?php foreach ($activeJobPosts as $post): ?>
                        <?php
                            $jobId = (int) ($post['id'] ?? 0);
                            $deadlineText = 'No deadline';
                            if (!empty($post['deadline']) && strtotime($post['deadline']) !== false) {
                                $deadlineText = 'Deadline: ' . date('M j, Y', strtotime($post['deadline']));
                            }
                        ?>
                        <div class="job-row">
                            <div>
                                <div class="row-title"><?= h($post['title'] ?? 'Untitled Job') ?></div>
                                <div class="row-meta">
                                    <?= h($post['employment_type'] ?? 'Job') ?>
                                    <?= !empty($post['location']) ? ' • ' . h($post['location']) : '' ?>
                                    <?= ' • ' . h($deadlineText) ?>
                                </div>
                                <div class="row-meta">
                                    <?= (int) ($applicantsPerJob[$jobId] ?? 0) ?> applicant<?= ((int) ($applicantsPerJob[$jobId] ?? 0)) === 1 ? '' : 's' ?> for this role
                                </div>
                            </div>

                            <div>
                                <span class="status-pill <?= statusClass($post['status'] ?? 'open') ?>"><?= h(formatStatusLabel($post['status'] ?? 'open')) ?></span>
                                <div class="job-actions">
                                    <?php if ($jobId > 0): ?>
                                        <a data-loader-text="Analyzing candidates with AI… finding the top 5 matches for this job." href="top_matches.php?job_id=<?= $jobId ?>" class="mini-btn">AI Top 5</a>
                                        <a href="applicants.php?job_id=<?= $jobId ?>" class="mini-btn">Applicants</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <aside>
            <div class="recruit-panel">
                <div class="panel-heading-row">
                    <div>
                        <div class="panel-heading-title">Quick Actions</div>
                        <div class="panel-heading-subtitle">Common recruitment shortcuts.</div>
                    </div>
                </div>

                <a href="create_job.php" class="quick-row">
                    <div class="candidate-info">
                        <div class="quick-icon">＋</div>
                        <div>
                            <div class="row-title">Create Job Post</div>
                            <div class="row-meta">Publish a new hiring opportunity</div>
                        </div>
                    </div>
                    <div>→</div>
                </a>

                <a href="job_posts.php" class="quick-row">
                    <div class="candidate-info">
                        <div class="quick-icon">☰</div>
                        <div>
                            <div class="row-title">Manage Job Posts</div>
                            <div class="row-meta">Edit openings and view AI matches</div>
                        </div>
                    </div>
                    <div>→</div>
                </a>

                <a href="applicants.php" class="quick-row">
                    <div class="candidate-info">
                        <div class="quick-icon">◎</div>
                        <div>
                            <div class="row-title">Review Applicants</div>
                            <div class="row-meta">Screen submitted applications</div>
                        </div>
                    </div>
                    <div>→</div>
                </a>

                <a href="edit_profile.php" class="quick-row">
                    <div class="candidate-info">
                        <div class="quick-icon">✎</div>
                        <div>
                            <div class="row-title">Company Profile</div>
                            <div class="row-meta">Update credibility details</div>
                        </div>
                    </div>
                    <div>→</div>
                </a>
            </div>

            <div class="recruit-panel">
                <div class="panel-heading-row">
                    <div>
                        <div class="panel-heading-title">Company Summary</div>
                        <div class="panel-heading-subtitle">Information shown to applicants.</div>
                    </div>
                </div>

                <div class="company-strip" style="grid-template-columns: 1fr;">
                    <div class="company-chip">
                        <span>Account Name</span>
                        <strong><?= h(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?></strong>
                    </div>
                    <div class="company-chip">
                        <span>Email</span>
                        <strong><?= h($profile['email'] ?? '—') ?></strong>
                    </div>
                    <div class="company-chip">
                        <span>Contact Person</span>
                        <strong><?= h($profile['contact_person'] ?? '—') ?></strong>
                    </div>
                    <div class="company-chip">
                        <span>Phone</span>
                        <strong><?= h($profile['phone'] ?? '—') ?></strong>
                    </div>
                    <div class="company-chip">
                        <span>Website</span>
                        <strong><?= h($profile['website'] ?? '—') ?></strong>
                    </div>
                </div>
            </div>
        </aside>
    </section>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
