<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$employmentFilter = trim($_GET['employment'] ?? '');

$allowedStatuses = ['Pending', 'Reviewed', 'Hired', 'Not Selected'];
$allowedEmployment = ['Employed', 'Unemployed', 'Self-Employed', 'Student'];

if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if ($employmentFilter !== '' && !in_array($employmentFilter, $allowedEmployment, true)) {
    $employmentFilter = '';
}

$sql = "
    SELECT 
        a.id AS application_id,
        a.status,
        a.applied_at,
        a.resume_id,
        a.applicant_name,
        a.applicant_email,
        a.applicant_phone,
        a.applicant_address,
        a.applicant_course,
        a.applicant_major,
        a.applicant_graduation_year,
        a.applicant_employment_status,
        a.applicant_skills,
        a.applicant_summary,
        jp.id AS job_post_id,
        jp.title AS job_title,
        pp.company_name,
        r.file_name AS resume_file_name,
        r.file_path AS resume_file_path
    FROM applications a
    INNER JOIN job_posts jp ON a.job_post_id = jp.id
    LEFT JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    LEFT JOIN resumes r ON a.resume_id = r.id
    WHERE 1=1
";

$params = [];

if ($search !== '') {
    $sql .= " AND (
        a.applicant_name LIKE ?
        OR a.applicant_email LIKE ?
        OR a.applicant_phone LIKE ?
        OR a.applicant_course LIKE ?
        OR a.applicant_major LIKE ?
        OR a.applicant_graduation_year LIKE ?
        OR jp.title LIKE ?
        OR pp.company_name LIKE ?
        OR a.status LIKE ?
    )";

    $searchLike = '%' . $search . '%';
    $params = array_merge($params, [
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    ]);
}

if ($statusFilter !== '') {
    $sql .= " AND a.status = ?";
    $params[] = $statusFilter;
}

if ($employmentFilter !== '') {
    $sql .= " AND a.applicant_employment_status = ?";
    $params[] = $employmentFilter;
}

$sql .= " ORDER BY a.applied_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

function applicationStatusBadgeClass(string $status): string {
    return match ($status) {
        'Pending' => 'status-pending',
        'Reviewed' => 'status-reviewed',
        'Hired' => 'status-hired',
        'Not Selected' => 'status-rejected',
        default => 'status-neutral'
    };
}

function employmentBadgeClass(string $status): string {
    return match ($status) {
        'Employed' => 'employment-employed',
        'Unemployed' => 'employment-unemployed',
        'Self-Employed' => 'employment-self',
        'Student' => 'employment-student',
        default => 'employment-neutral'
    };
}

function safeDate(?string $date): string {
    if (empty($date)) {
        return '—';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M j, Y', $timestamp) : '—';
}

function initialsFromName(?string $name): string {
    $name = trim((string) $name);
    if ($name === '') {
        return 'AP';
    }

    $parts = preg_split('/\s+/', $name);
    $first = strtoupper(substr($parts[0] ?? 'A', 0, 1));
    $last = strtoupper(substr($parts[count($parts) - 1] ?? 'P', 0, 1));
    return $first . $last;
}

$totalApplications = count($applications);
$pendingCount = count(array_filter($applications, fn($row) => ($row['status'] ?? '') === 'Pending'));
$reviewedCount = count(array_filter($applications, fn($row) => ($row['status'] ?? '') === 'Reviewed'));
$hiredCount = count(array_filter($applications, fn($row) => ($row['status'] ?? '') === 'Hired'));
$notSelectedCount = count(array_filter($applications, fn($row) => ($row['status'] ?? '') === 'Not Selected'));
$withResumeCount = count(array_filter($applications, fn($row) => !empty($row['resume_file_path'])));
$hiringRate = $totalApplications > 0 ? round(($hiredCount / $totalApplications) * 100) : 0;

$pageTitle = 'Application Operations';
$pageSubtitle = 'Review applicant records, resumes, and hiring activity across all job posts.';
$activePage = 'applications';

require_once '../includes/dashboard_header.php';
?>

<style>
    :root {
        --admin-maroon: #7b1113;
        --admin-maroon-dark: #4f0809;
        --admin-maroon-soft: #fff2f2;
        --admin-ink: #1f2937;
        --admin-muted: #6b7280;
        --admin-border: #eadede;
        --admin-bg: #fbf7f7;
        --admin-card: #ffffff;
    }

    .ops-hero {
        position: relative;
        overflow: hidden;
        border-radius: 26px;
        padding: 30px;
        color: #fff;
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.22), transparent 34%),
            linear-gradient(135deg, var(--admin-maroon-dark), var(--admin-maroon));
        box-shadow: 0 24px 50px rgba(79, 8, 9, 0.22);
        margin-bottom: 22px;
    }

    .ops-hero::after {
        content: '';
        position: absolute;
        width: 260px;
        height: 260px;
        right: -80px;
        bottom: -130px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.10);
    }

    .ops-hero-content {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 24px;
        align-items: center;
    }

    .ops-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.18);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .ops-title {
        margin: 14px 0 8px;
        font-size: clamp(28px, 4vw, 44px);
        font-weight: 900;
        letter-spacing: -1.2px;
    }

    .ops-text {
        color: rgba(255, 255, 255, 0.82);
        max-width: 700px;
        line-height: 1.7;
        margin: 0;
    }

    .ops-hero-meter {
        min-width: 220px;
        padding: 18px;
        border-radius: 22px;
        background: rgba(255, 255, 255, 0.13);
        border: 1px solid rgba(255, 255, 255, 0.20);
        backdrop-filter: blur(8px);
    }

    .ops-hero-meter .label {
        font-size: 12px;
        color: rgba(255, 255, 255, 0.72);
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 800;
    }

    .ops-hero-meter .value {
        font-size: 42px;
        font-weight: 900;
        line-height: 1;
        margin-top: 8px;
    }

    .ops-hero-meter .note {
        margin-top: 8px;
        color: rgba(255, 255, 255, 0.74);
        font-size: 13px;
    }

    .ops-stats {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .ops-stat-card,
    .ops-panel,
    .application-card {
        background: var(--admin-card);
        border: 1px solid var(--admin-border);
        border-radius: 22px;
        box-shadow: 0 18px 40px rgba(127, 29, 29, 0.07);
    }

    .ops-stat-card {
        padding: 18px;
    }

    .ops-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--admin-maroon-soft);
        color: var(--admin-maroon);
        font-weight: 900;
        margin-bottom: 12px;
    }

    .ops-stat-label {
        color: var(--admin-muted);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .ops-stat-value {
        color: var(--admin-ink);
        font-size: 28px;
        font-weight: 900;
        line-height: 1.1;
        margin-top: 6px;
    }

    .ops-stat-note {
        color: var(--admin-muted);
        font-size: 13px;
        margin-top: 6px;
    }

    .ops-panel {
        padding: 20px;
        margin-bottom: 22px;
    }

    .ops-panel-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 16px;
    }

    .ops-panel-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--admin-ink);
        margin-bottom: 4px;
    }

    .ops-panel-subtitle {
        color: var(--admin-muted);
        font-size: 14px;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: minmax(220px, 1.7fr) minmax(150px, 0.7fr) minmax(150px, 0.7fr) auto auto;
        gap: 12px;
        align-items: end;
    }

    .admin-input,
    .admin-select {
        width: 100%;
        border: 1px solid #eadede;
        border-radius: 14px;
        padding: 12px 14px;
        color: var(--admin-ink);
        background: #fff;
        outline: none;
        transition: 0.2s ease;
    }

    .admin-input:focus,
    .admin-select:focus {
        border-color: rgba(123, 17, 19, 0.45);
        box-shadow: 0 0 0 4px rgba(123, 17, 19, 0.08);
    }

    .admin-label {
        display: block;
        margin-bottom: 7px;
        color: #5b4b4b;
        font-weight: 800;
        font-size: 12px;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .btn-admin-primary,
    .btn-admin-outline,
    .btn-admin-danger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 15px;
        border-radius: 14px;
        font-weight: 800;
        text-decoration: none;
        border: 1px solid transparent;
        transition: 0.18s ease;
        white-space: nowrap;
    }

    .btn-admin-primary {
        background: linear-gradient(135deg, var(--admin-maroon), var(--admin-maroon-dark));
        color: #fff;
        box-shadow: 0 12px 24px rgba(123, 17, 19, 0.18);
    }

    .btn-admin-primary:hover {
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 16px 30px rgba(123, 17, 19, 0.24);
    }

    .btn-admin-outline {
        color: var(--admin-maroon);
        background: #fff;
        border-color: #e4caca;
    }

    .btn-admin-outline:hover {
        color: var(--admin-maroon-dark);
        background: var(--admin-maroon-soft);
        border-color: #d9b5b5;
    }

    .btn-admin-danger {
        color: #b42318;
        background: #fff5f5;
        border-color: #ffd3d3;
    }

    .btn-admin-danger:hover {
        color: #7a1c14;
        background: #ffecec;
    }

    .application-list {
        display: grid;
        gap: 16px;
    }

    .application-card {
        overflow: hidden;
    }

    .application-top {
        padding: 18px 20px;
        border-bottom: 1px solid #f1e4e4;
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 14px;
        align-items: start;
    }

    .avatar-token {
        width: 48px;
        height: 48px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, var(--admin-maroon), var(--admin-maroon-dark));
        color: #fff;
        font-weight: 900;
        letter-spacing: -0.6px;
        box-shadow: 0 12px 22px rgba(123, 17, 19, 0.18);
    }

    .applicant-name {
        font-size: 17px;
        font-weight: 900;
        color: var(--admin-ink);
        margin-bottom: 4px;
    }

    .job-line {
        color: var(--admin-muted);
        font-size: 14px;
        line-height: 1.5;
    }

    .application-body {
        padding: 18px 20px;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 16px;
    }

    .detail-box {
        background: #fffafa;
        border: 1px solid #f0dfdf;
        border-radius: 16px;
        padding: 12px;
        min-height: 78px;
    }

    .detail-key {
        color: #8a7474;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 900;
        margin-bottom: 5px;
    }

    .detail-value {
        color: var(--admin-ink);
        font-weight: 800;
        word-break: break-word;
    }

    .summary-box {
        background: linear-gradient(180deg, #fff, #fffafa);
        border: 1px solid #f0dfdf;
        border-radius: 18px;
        padding: 14px;
        color: #514545;
        line-height: 1.7;
        margin-top: 12px;
    }

    .skill-text {
        white-space: pre-line;
    }

    .application-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        padding: 16px 20px;
        border-top: 1px solid #f1e4e4;
        background: #fffafa;
    }

    .action-note {
        color: var(--admin-muted);
        font-size: 13px;
        font-weight: 700;
    }

    .status-pill,
    .employment-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 8px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .status-pending { background: #fff8e6; color: #9a6700; border-color: #ffe5a3; }
    .status-reviewed { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .status-hired { background: #ecfdf3; color: #087443; border-color: #b7f0cb; }
    .status-rejected { background: #fff1f2; color: #b42318; border-color: #fecdd3; }
    .status-neutral { background: #f3f4f6; color: #4b5563; border-color: #e5e7eb; }

    .employment-employed { background: #ecfdf3; color: #087443; border-color: #b7f0cb; }
    .employment-unemployed { background: #fff1f2; color: #b42318; border-color: #fecdd3; }
    .employment-self { background: #fff8e6; color: #9a6700; border-color: #ffe5a3; }
    .employment-student { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .employment-neutral { background: #f3f4f6; color: #4b5563; border-color: #e5e7eb; }

    .empty-state {
        padding: 50px 18px;
        text-align: center;
        border: 1px dashed #e4caca;
        border-radius: 22px;
        background: #fffafa;
    }

    .empty-icon {
        width: 66px;
        height: 66px;
        border-radius: 22px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--admin-maroon-soft);
        color: var(--admin-maroon);
        font-size: 28px;
        margin-bottom: 14px;
    }

    @media (max-width: 1180px) {
        .ops-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 768px) {
        .ops-hero { padding: 24px; }
        .ops-hero-content { grid-template-columns: 1fr; }
        .ops-hero-meter { min-width: 0; }
        .ops-stats { grid-template-columns: 1fr; }
        .filter-grid { grid-template-columns: 1fr; }
        .application-top { grid-template-columns: 1fr; }
        .detail-grid { grid-template-columns: 1fr; }
        .application-actions { align-items: stretch; }
        .application-actions > div:last-child { display: grid; width: 100%; }
    }
</style>

<section class="ops-hero">
    <div class="ops-hero-content">
        <div>
            <div class="ops-kicker">Application Operations</div>
            <h1 class="ops-title">Applicant Review Center</h1>
            <p class="ops-text">
                Monitor job applications submitted by alumni, review resumes, and audit applicant information across all partner job posts.
            </p>
        </div>

        <div class="ops-hero-meter">
            <div class="label">Hiring Rate</div>
            <div class="value"><?= $hiringRate ?>%</div>
            <div class="note"><?= $hiredCount ?> hired from <?= $totalApplications ?> matching application(s)</div>
        </div>
    </div>
</section>

<section class="ops-stats">
    <div class="ops-stat-card">
        <div class="ops-stat-icon">Σ</div>
        <div class="ops-stat-label">Total</div>
        <div class="ops-stat-value"><?= $totalApplications ?></div>
        <div class="ops-stat-note">Matching applications</div>
    </div>

    <div class="ops-stat-card">
        <div class="ops-stat-icon">⏳</div>
        <div class="ops-stat-label">Pending</div>
        <div class="ops-stat-value"><?= $pendingCount ?></div>
        <div class="ops-stat-note">Awaiting review</div>
    </div>

    <div class="ops-stat-card">
        <div class="ops-stat-icon">✓</div>
        <div class="ops-stat-label">Reviewed</div>
        <div class="ops-stat-value"><?= $reviewedCount ?></div>
        <div class="ops-stat-note">Already checked</div>
    </div>

    <div class="ops-stat-card">
        <div class="ops-stat-icon">★</div>
        <div class="ops-stat-label">Hired</div>
        <div class="ops-stat-value"><?= $hiredCount ?></div>
        <div class="ops-stat-note">Successful applicants</div>
    </div>

    <div class="ops-stat-card">
        <div class="ops-stat-icon">📄</div>
        <div class="ops-stat-label">With Resume</div>
        <div class="ops-stat-value"><?= $withResumeCount ?></div>
        <div class="ops-stat-note">Resume attached</div>
    </div>
</section>

<section class="ops-panel">
    <div class="ops-panel-head">
        <div>
            <div class="ops-panel-title">Search and Filter</div>
            <div class="ops-panel-subtitle">Find applications by applicant, job, company, status, course, or employment profile.</div>
        </div>
        <?php if ($search !== '' || $statusFilter !== '' || $employmentFilter !== ''): ?>
            <a href="applications.php" class="btn-admin-outline">Clear Filters</a>
        <?php endif; ?>
    </div>

    <form method="GET" action="">
        <div class="filter-grid">
            <div>
                <label class="admin-label">Search</label>
                <input
                    type="text"
                    name="search"
                    class="admin-input"
                    placeholder="Applicant, email, phone, course, company, job title..."
                    value="<?= htmlspecialchars($search) ?>"
                >
            </div>

            <div>
                <label class="admin-label">Application Status</label>
                <select name="status" class="admin-select">
                    <option value="">All Statuses</option>
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= htmlspecialchars($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="admin-label">Employment Profile</label>
                <select name="employment" class="admin-select">
                    <option value="">All Profiles</option>
                    <?php foreach ($allowedEmployment as $employment): ?>
                        <option value="<?= htmlspecialchars($employment) ?>" <?= $employmentFilter === $employment ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employment) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit" class="btn-admin-primary">Apply</button>
            </div>

            <div>
                <a href="job_posts.php" class="btn-admin-outline">Job Posts</a>
            </div>
        </div>
    </form>
</section>

<section class="ops-panel">
    <div class="ops-panel-head">
        <div>
            <div class="ops-panel-title">Application Records</div>
            <div class="ops-panel-subtitle">
                <?= $totalApplications ?> application(s) found. Admin can review and audit records here; applicant status updates are handled outside this view.
            </div>
        </div>
    </div>

    <?php if (empty($applications)): ?>
        <div class="empty-state">
            <div class="empty-icon">⌕</div>
            <h4>No applications found</h4>
            <p class="text-muted mb-0">Try changing the search keyword or clearing the selected filters.</p>
        </div>
    <?php else: ?>
        <div class="application-list">
            <?php foreach ($applications as $app): ?>
                <article class="application-card">
                    <div class="application-top">
                        <div class="avatar-token">
                            <?= htmlspecialchars(initialsFromName($app['applicant_name'] ?? '')) ?>
                        </div>

                        <div>
                            <div class="applicant-name"><?= htmlspecialchars($app['applicant_name'] ?? 'Unnamed Applicant') ?></div>
                            <div class="job-line">
                                Applied for <strong><?= htmlspecialchars($app['job_title'] ?? 'Unknown Job') ?></strong>
                                <?php if (!empty($app['company_name'])): ?>
                                    at <strong><?= htmlspecialchars($app['company_name']) ?></strong>
                                <?php endif; ?>
                            </div>
                            <div class="job-line mt-1">
                                Submitted <?= htmlspecialchars(safeDate($app['applied_at'] ?? null)) ?>
                            </div>
                        </div>

                        <div class="d-flex flex-column align-items-start gap-2">
                            <span class="status-pill <?= applicationStatusBadgeClass($app['status'] ?? '') ?>">
                                <?= htmlspecialchars($app['status'] ?? 'Unknown') ?>
                            </span>
                            <span class="employment-pill <?= employmentBadgeClass($app['applicant_employment_status'] ?? '') ?>">
                                <?= htmlspecialchars($app['applicant_employment_status'] ?: 'Employment not specified') ?>
                            </span>
                        </div>
                    </div>

                    <div class="application-body">
                        <div class="detail-grid">
                            <div class="detail-box">
                                <div class="detail-key">Email</div>
                                <div class="detail-value"><?= htmlspecialchars($app['applicant_email'] ?? '—') ?></div>
                            </div>

                            <div class="detail-box">
                                <div class="detail-key">Phone</div>
                                <div class="detail-value"><?= htmlspecialchars($app['applicant_phone'] ?? '—') ?></div>
                            </div>

                            <div class="detail-box">
                                <div class="detail-key">Course</div>
                                <div class="detail-value"><?= htmlspecialchars($app['applicant_course'] ?? '—') ?></div>
                            </div>

                            <div class="detail-box">
                                <div class="detail-key">Graduation Year</div>
                                <div class="detail-value"><?= htmlspecialchars($app['applicant_graduation_year'] ?? '—') ?></div>
                            </div>
                        </div>

                        <div class="detail-grid">
                            <div class="detail-box">
                                <div class="detail-key">Major</div>
                                <div class="detail-value"><?= htmlspecialchars($app['applicant_major'] ?? '—') ?></div>
                            </div>

                            <div class="detail-box">
                                <div class="detail-key">Address</div>
                                <div class="detail-value"><?= htmlspecialchars($app['applicant_address'] ?? '—') ?></div>
                            </div>

                            <div class="detail-box">
                                <div class="detail-key">Resume</div>
                                <div class="detail-value"><?= htmlspecialchars($app['resume_file_name'] ?? 'No file') ?></div>
                            </div>

                            <div class="detail-box">
                                <div class="detail-key">Application ID</div>
                                <div class="detail-value">#<?= htmlspecialchars((string) $app['application_id']) ?></div>
                            </div>
                        </div>

                        <?php if (!empty($app['applicant_skills'])): ?>
                            <div class="summary-box">
                                <strong>Skills</strong>
                                <div class="skill-text mt-1"><?= htmlspecialchars($app['applicant_skills']) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($app['applicant_summary'])): ?>
                            <div class="summary-box">
                                <strong>Applicant Summary</strong>
                                <div class="mt-1"><?= nl2br(htmlspecialchars($app['applicant_summary'])) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="application-actions">
                        <div class="action-note">
                            Status action button removed for admin view. Use this page for review, audit, resume access, and record management.
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <?php if (!empty($app['resume_file_path'])): ?>
                                <a href="../<?= htmlspecialchars($app['resume_file_path']) ?>" class="btn-admin-outline" target="_blank">
                                    View Resume
                                </a>
                            <?php endif; ?>

                            <a href="delete_application.php?id=<?= urlencode((string) $app['application_id']) ?>"
                               class="btn-admin-danger"
                               onclick="return confirm('Are you sure you want to delete this application?');">
                                Delete
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once '../includes/dashboard_footer.php'; ?>
