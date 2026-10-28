<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT 
        a.*,
        jp.title,
        jp.location,
        jp.employment_type,
        jp.salary_range,
        jp.deadline,
        pp.company_name
    FROM applications a
    INNER JOIN job_posts jp ON a.job_post_id = jp.id
    INNER JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    WHERE a.alumni_id = ?
    ORDER BY a.applied_at DESC
");
$stmt->execute([$user_id]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

function appStatusKey($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === '') {
        return 'pending';
    }

    return preg_replace('/[^a-z0-9]+/', '-', $status);
}

function appStatusClass($status)
{
    $key = appStatusKey($status);

    $map = [
        'pending' => 'status-pending',
        'reviewed' => 'status-reviewed',
        'hired' => 'status-hired',
        'accepted' => 'status-hired',
        'rejected' => 'status-rejected',
        'declined' => 'status-rejected',
        'shortlisted' => 'status-shortlisted',
        'for-interview' => 'status-interview',
        'interview' => 'status-interview',
    ];

    return $map[$key] ?? 'status-default';
}

function appFormatDate($date, $format = 'M j, Y')
{
    if (empty($date)) {
        return '—';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date($format, $timestamp) : '—';
}

function appDeadlineInfo($deadline)
{
    if (empty($deadline)) {
        return [
            'label' => 'No deadline posted',
            'class' => 'deadline-neutral'
        ];
    }

    $today = strtotime(date('Y-m-d'));
    $deadlineTime = strtotime(date('Y-m-d', strtotime($deadline)));

    if (!$deadlineTime) {
        return [
            'label' => 'Deadline unavailable',
            'class' => 'deadline-neutral'
        ];
    }

    $daysLeft = (int) floor(($deadlineTime - $today) / 86400);

    if ($daysLeft < 0) {
        return [
            'label' => 'Closed ' . abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' ago',
            'class' => 'deadline-closed'
        ];
    }

    if ($daysLeft === 0) {
        return [
            'label' => 'Deadline today',
            'class' => 'deadline-warning'
        ];
    }

    if ($daysLeft <= 7) {
        return [
            'label' => $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left',
            'class' => 'deadline-warning'
        ];
    }

    return [
        'label' => $daysLeft . ' days left',
        'class' => 'deadline-open'
    ];
}

$totalApplications = count($applications);
$pendingCount = 0;
$reviewedCount = 0;
$hiredCount = 0;
$activeCount = 0;
$statusCounts = [];

foreach ($applications as $app) {
    $key = appStatusKey($app['status'] ?? 'Pending');
    $statusCounts[$key] = ($statusCounts[$key] ?? 0) + 1;

    if ($key === 'pending') {
        $pendingCount++;
    }

    if ($key === 'reviewed') {
        $reviewedCount++;
    }

    if (in_array($key, ['hired', 'accepted'], true)) {
        $hiredCount++;
    }

    if (!in_array($key, ['hired', 'accepted', 'rejected', 'declined'], true)) {
        $activeCount++;
    }
}

$pageTitle = 'My Applications';
$pageSubtitle = 'Track your submitted job applications and current status.';
$activePage = 'applications';

require_once '../includes/dashboard_header.php';
?>

<style>
    .applications-modern {
        --app-maroon: #800000;
        --app-maroon-dark: #5f0000;
        --app-maroon-soft: #fff2f2;
        --app-gold: #f4b400;
        --app-ink: #211818;
        --app-muted: #766666;
        --app-border: #f0dddd;
        --app-surface: #ffffff;
        --app-bg-soft: #fffafa;
    }

    .applications-modern .app-hero {
        position: relative;
        overflow: hidden;
        background:
            radial-gradient(circle at 85% 10%, rgba(255,255,255,0.25), transparent 32%),
            linear-gradient(135deg, var(--app-maroon), var(--app-maroon-dark));
        border-radius: 28px;
        padding: 30px;
        color: #ffffff;
        box-shadow: 0 22px 46px rgba(128, 0, 0, 0.22);
        margin-bottom: 24px;
    }

    .applications-modern .app-hero::after {
        content: "";
        position: absolute;
        width: 220px;
        height: 220px;
        border: 1px solid rgba(255,255,255,0.18);
        border-radius: 999px;
        right: -70px;
        bottom: -90px;
    }

    .applications-modern .app-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1.7fr) minmax(280px, 0.8fr);
        gap: 24px;
        align-items: stretch;
    }

    .applications-modern .app-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: rgba(255,255,255,0.15);
        border: 1px solid rgba(255,255,255,0.18);
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .applications-modern .app-hero-title {
        margin-top: 18px;
        font-size: clamp(28px, 4vw, 44px);
        line-height: 1.05;
        font-weight: 900;
        letter-spacing: -1.1px;
    }

    .applications-modern .app-hero-text {
        margin-top: 12px;
        max-width: 720px;
        color: rgba(255,255,255,0.86);
        font-size: 15px;
        line-height: 1.7;
    }

    .applications-modern .app-hero-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 24px;
    }

    .applications-modern .app-btn-primary,
    .applications-modern .app-btn-light,
    .applications-modern .app-btn-outline {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 11px 16px;
        border-radius: 14px;
        font-weight: 800;
        font-size: 14px;
        text-decoration: none;
        transition: 0.2s ease;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .applications-modern .app-btn-primary {
        background: #ffffff;
        color: var(--app-maroon);
        box-shadow: 0 14px 24px rgba(0,0,0,0.14);
    }

    .applications-modern .app-btn-primary:hover {
        transform: translateY(-2px);
        color: var(--app-maroon-dark);
    }

    .applications-modern .app-btn-light {
        background: rgba(255,255,255,0.12);
        color: #ffffff;
        border-color: rgba(255,255,255,0.25);
    }

    .applications-modern .app-btn-light:hover {
        background: rgba(255,255,255,0.2);
        color: #ffffff;
        transform: translateY(-2px);
    }

    .applications-modern .app-hero-panel {
        background: rgba(255,255,255,0.14);
        border: 1px solid rgba(255,255,255,0.18);
        border-radius: 24px;
        padding: 20px;
        backdrop-filter: blur(12px);
    }

    .applications-modern .app-hero-panel .panel-label {
        color: rgba(255,255,255,0.72);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .applications-modern .app-hero-panel .panel-number {
        margin-top: 8px;
        font-size: 54px;
        font-weight: 900;
        line-height: 1;
    }

    .applications-modern .app-hero-panel .panel-caption {
        margin-top: 10px;
        color: rgba(255,255,255,0.82);
        line-height: 1.5;
        font-size: 14px;
    }

    .applications-modern .app-mini-progress {
        margin-top: 18px;
        height: 10px;
        background: rgba(255,255,255,0.18);
        border-radius: 999px;
        overflow: hidden;
    }

    .applications-modern .app-mini-progress span {
        display: block;
        height: 100%;
        background: #ffffff;
        border-radius: inherit;
    }

    .applications-modern .app-stats-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .applications-modern .app-stat-card {
        background: var(--app-surface);
        border: 1px solid var(--app-border);
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 14px 32px rgba(128, 0, 0, 0.06);
        position: relative;
        overflow: hidden;
    }

    .applications-modern .app-stat-card::before {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 5px;
        background: linear-gradient(180deg, var(--app-maroon), var(--app-gold));
        opacity: 0.9;
    }

    .applications-modern .app-stat-label {
        color: var(--app-muted);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .applications-modern .app-stat-value {
        margin-top: 7px;
        color: var(--app-ink);
        font-size: 30px;
        font-weight: 900;
        line-height: 1;
    }

    .applications-modern .app-stat-note {
        margin-top: 7px;
        color: var(--app-muted);
        font-size: 13px;
    }

    .applications-modern .app-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        flex-wrap: wrap;
        background: var(--app-surface);
        border: 1px solid var(--app-border);
        border-radius: 24px;
        padding: 18px;
        box-shadow: 0 14px 32px rgba(128, 0, 0, 0.05);
        margin-bottom: 18px;
    }

    .applications-modern .section-title {
        color: var(--app-ink);
        font-size: 20px;
        font-weight: 900;
        letter-spacing: -0.3px;
        margin: 0;
    }

    .applications-modern .section-subtitle {
        color: var(--app-muted);
        margin-top: 4px;
        font-size: 13px;
    }

    .applications-modern .filter-pills {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .applications-modern .filter-pill {
        border: 1px solid var(--app-border);
        background: #ffffff;
        color: var(--app-muted);
        padding: 9px 12px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 800;
        transition: 0.2s ease;
    }

    .applications-modern .filter-pill:hover,
    .applications-modern .filter-pill.active {
        background: var(--app-maroon);
        border-color: var(--app-maroon);
        color: #ffffff;
    }

    .applications-modern .applications-list {
        display: grid;
        gap: 16px;
    }

    .applications-modern .application-card {
        background: var(--app-surface);
        border: 1px solid var(--app-border);
        border-radius: 26px;
        padding: 20px;
        box-shadow: 0 14px 36px rgba(128, 0, 0, 0.06);
        transition: 0.2s ease;
    }

    .applications-modern .application-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 42px rgba(128, 0, 0, 0.10);
    }

    .applications-modern .application-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }

    .applications-modern .job-title {
        color: var(--app-ink);
        font-size: 18px;
        font-weight: 900;
        letter-spacing: -0.2px;
        margin: 0;
    }

    .applications-modern .company-name {
        margin-top: 5px;
        color: var(--app-maroon);
        font-weight: 800;
        font-size: 14px;
    }

    .applications-modern .meta-row {
        margin-top: 14px;
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .applications-modern .meta-chip {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 8px 10px;
        border-radius: 999px;
        background: #fff7f7;
        border: 1px solid #f2dddd;
        color: #5e4c4c;
        font-size: 12px;
        font-weight: 800;
    }

    .applications-modern .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border: 1px solid transparent;
    }

    .applications-modern .status-badge::before {
        content: "";
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: currentColor;
    }

    .applications-modern .status-pending {
        background: #fff8e1;
        color: #8a6100;
        border-color: #ffe7a3;
    }

    .applications-modern .status-reviewed {
        background: #eef5ff;
        color: #1f5c9e;
        border-color: #cfe4ff;
    }

    .applications-modern .status-hired {
        background: #ecfdf3;
        color: #087443;
        border-color: #bcebd1;
    }

    .applications-modern .status-rejected {
        background: #fff0f0;
        color: #b42318;
        border-color: #ffc9c9;
    }

    .applications-modern .status-shortlisted,
    .applications-modern .status-interview {
        background: #f5f0ff;
        color: #6941c6;
        border-color: #ded0ff;
    }

    .applications-modern .status-default {
        background: #f5f5f5;
        color: #5d5d5d;
        border-color: #e8e8e8;
    }

    .applications-modern .application-bottom {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid #f3e5e5;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
    }

    .applications-modern .timeline-note {
        color: var(--app-muted);
        font-size: 13px;
        line-height: 1.5;
    }

    .applications-modern .deadline-open {
        color: #087443;
    }

    .applications-modern .deadline-warning {
        color: #a15c00;
    }

    .applications-modern .deadline-closed {
        color: #b42318;
    }

    .applications-modern .deadline-neutral {
        color: var(--app-muted);
    }

    .applications-modern .app-btn-outline {
        background: #ffffff;
        color: var(--app-maroon);
        border-color: #e9caca;
    }

    .applications-modern .app-btn-outline:hover {
        background: var(--app-maroon);
        color: #ffffff;
        transform: translateY(-2px);
    }

    .applications-modern .empty-state {
        background: #ffffff;
        border: 1px dashed #e8caca;
        border-radius: 28px;
        padding: 44px 24px;
        text-align: center;
        box-shadow: 0 14px 32px rgba(128, 0, 0, 0.04);
    }

    .applications-modern .empty-icon {
        width: 72px;
        height: 72px;
        margin: 0 auto 18px;
        border-radius: 24px;
        display: grid;
        place-items: center;
        background: var(--app-maroon-soft);
        color: var(--app-maroon);
        font-size: 30px;
        font-weight: 900;
    }

    .applications-modern .empty-title {
        font-size: 22px;
        font-weight: 900;
        color: var(--app-ink);
    }

    .applications-modern .empty-text {
        margin: 8px auto 0;
        max-width: 520px;
        color: var(--app-muted);
        line-height: 1.6;
    }

    .applications-modern .app-alert {
        border-radius: 18px;
        border: 1px solid var(--app-border);
        box-shadow: 0 10px 24px rgba(128, 0, 0, 0.05);
    }

    @media (max-width: 992px) {
        .applications-modern .app-hero-grid,
        .applications-modern .app-stats-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    @media (max-width: 768px) {
        .applications-modern .app-hero {
            padding: 22px;
            border-radius: 22px;
        }

        .applications-modern .app-hero-grid,
        .applications-modern .app-stats-grid {
            grid-template-columns: 1fr;
        }

        .applications-modern .app-toolbar,
        .applications-modern .application-bottom {
            align-items: stretch;
        }

        .applications-modern .filter-pills,
        .applications-modern .app-hero-actions {
            width: 100%;
        }

        .applications-modern .filter-pill,
        .applications-modern .app-btn-primary,
        .applications-modern .app-btn-light,
        .applications-modern .app-btn-outline {
            flex: 1 1 auto;
        }
    }
</style>

<div class="applications-modern">
    <div class="app-hero">
        <div class="app-hero-grid">
            <div>
                <span class="app-eyebrow">Application Tracker</span>
                <div class="app-hero-title">My Applications</div>
                <div class="app-hero-text">
                    Track every job application you have submitted, review your progress, and quickly return to job posts when you need more details.
                </div>

                <div class="app-hero-actions">
                    <a href="recommended_jobs.php" class="app-btn-primary">Browse More Jobs</a>
                    <a href="dashboard.php" class="app-btn-light">Back to Dashboard</a>
                </div>
            </div>

            <div class="app-hero-panel">
                <div class="panel-label">Total Applications</div>
                <div class="panel-number"><?= $totalApplications ?></div>
                <div class="panel-caption">
                    <?= $totalApplications > 0 ? 'You currently have ' . $activeCount . ' active application' . ($activeCount === 1 ? '' : 's') . ' being tracked.' : 'Start applying to jobs to build your application history.' ?>
                </div>
                <div class="app-mini-progress" aria-hidden="true">
                    <?php $progressWidth = $totalApplications > 0 ? min(100, max(12, ($hiredCount / max(1, $totalApplications)) * 100)) : 0; ?>
                    <span style="width: <?= $progressWidth ?>%;"></span>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'success') ?> app-alert mb-4">
            <?= htmlspecialchars($_SESSION['flash_message']) ?>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <div class="app-stats-grid">
        <div class="app-stat-card">
            <div class="app-stat-label">All Applications</div>
            <div class="app-stat-value"><?= $totalApplications ?></div>
            <div class="app-stat-note">Total submitted jobs</div>
        </div>

        <div class="app-stat-card">
            <div class="app-stat-label">Pending</div>
            <div class="app-stat-value"><?= $pendingCount ?></div>
            <div class="app-stat-note">Waiting for review</div>
        </div>

        <div class="app-stat-card">
            <div class="app-stat-label">Reviewed</div>
            <div class="app-stat-value"><?= $reviewedCount ?></div>
            <div class="app-stat-note">Checked by employer</div>
        </div>

        <div class="app-stat-card">
            <div class="app-stat-label">Hired</div>
            <div class="app-stat-value"><?= $hiredCount ?></div>
            <div class="app-stat-note">Successful applications</div>
        </div>
    </div>

    <div class="app-toolbar">
        <div>
            <h2 class="section-title">Submitted Applications</h2>
            <div class="section-subtitle">Review your submitted roles and their latest application status.</div>
        </div>

        <?php if (!empty($applications)): ?>
            <div class="filter-pills" role="group" aria-label="Application status filter">
                <button type="button" class="filter-pill active" data-filter="all">All</button>
                <button type="button" class="filter-pill" data-filter="pending">Pending</button>
                <button type="button" class="filter-pill" data-filter="reviewed">Reviewed</button>
                <button type="button" class="filter-pill" data-filter="hired">Hired</button>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($applications)): ?>
        <div class="empty-state">
            <div class="empty-icon">+</div>
            <div class="empty-title">No applications yet</div>
            <div class="empty-text">
                You have not applied to any job posts yet. Browse recommended jobs and submit your first application when you find a role that fits you.
            </div>
            <div class="mt-4">
                <a href="recommended_jobs.php" class="app-btn-outline">Browse Recommended Jobs</a>
            </div>
        </div>
    <?php else: ?>
        <div class="applications-list" id="applicationsList">
            <?php foreach ($applications as $app): ?>
                <?php
                    $status = trim((string) ($app['status'] ?? 'Pending')) ?: 'Pending';
                    $statusKey = appStatusKey($status);
                    $deadlineInfo = appDeadlineInfo($app['deadline'] ?? null);
                ?>
                <article class="application-card" data-status="<?= htmlspecialchars($statusKey) ?>">
                    <div class="application-top">
                        <div>
                            <h3 class="job-title"><?= htmlspecialchars($app['title'] ?? 'Untitled Job') ?></h3>
                            <div class="company-name"><?= htmlspecialchars($app['company_name'] ?? 'Company unavailable') ?></div>

                            <div class="meta-row">
                                <?php if (!empty($app['location'])): ?>
                                    <span class="meta-chip">Location: <?= htmlspecialchars($app['location']) ?></span>
                                <?php endif; ?>

                                <?php if (!empty($app['employment_type'])): ?>
                                    <span class="meta-chip">Type: <?= htmlspecialchars($app['employment_type']) ?></span>
                                <?php endif; ?>

                                <?php if (!empty($app['salary_range'])): ?>
                                    <span class="meta-chip">Salary: <?= htmlspecialchars($app['salary_range']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <span class="status-badge <?= htmlspecialchars(appStatusClass($status)) ?>">
                            <?= htmlspecialchars($status) ?>
                        </span>
                    </div>

                    <div class="application-bottom">
                        <div class="timeline-note">
                            <strong>Applied:</strong> <?= htmlspecialchars(appFormatDate($app['applied_at'] ?? null, 'F j, Y')) ?>
                            <br>
                            <strong>Deadline:</strong>
                            <span class="<?= htmlspecialchars($deadlineInfo['class']) ?>">
                                <?= htmlspecialchars(appFormatDate($app['deadline'] ?? null, 'F j, Y')) ?>
                                <?= !empty($deadlineInfo['label']) ? ' - ' . htmlspecialchars($deadlineInfo['label']) : '' ?>
                            </span>
                        </div>

                        <a href="view_job.php?id=<?= (int) ($app['job_post_id'] ?? 0) ?>" class="app-btn-outline">View Job Details</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const filterButtons = document.querySelectorAll('.applications-modern .filter-pill');
        const cards = document.querySelectorAll('.applications-modern .application-card');

        filterButtons.forEach(button => {
            button.addEventListener('click', function () {
                const filter = this.dataset.filter;

                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');

                cards.forEach(card => {
                    const status = card.dataset.status;
                    card.style.display = (filter === 'all' || status === filter) ? '' : 'none';
                });
            });
        });
    });
</script>

<?php require_once '../includes/dashboard_footer.php'; ?>
