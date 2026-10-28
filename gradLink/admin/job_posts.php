<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$deadlineFilter = trim($_GET['deadline'] ?? '');

$sql = "
    SELECT 
        jp.id,
        jp.title,
        jp.description,
        jp.requirements,
        jp.location,
        jp.employment_type,
        jp.salary_range,
        jp.deadline,
        jp.status,
        jp.created_at,
        u.first_name,
        u.last_name,
        pp.company_name,
        pp.industry_type
    FROM job_posts jp
    LEFT JOIN users u ON jp.partner_id = u.id
    LEFT JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    WHERE 1=1
";

$params = [];

if ($search !== '') {
    $sql .= " AND (
        jp.title LIKE ?
        OR jp.description LIKE ?
        OR jp.requirements LIKE ?
        OR jp.location LIKE ?
        OR jp.employment_type LIKE ?
        OR jp.status LIKE ?
        OR pp.company_name LIKE ?
        OR pp.industry_type LIKE ?
        OR u.first_name LIKE ?
        OR u.last_name LIKE ?
    )";

    $searchLike = '%' . $search . '%';
    $params = array_merge($params, array_fill(0, 10, $searchLike));
}

if ($statusFilter !== '') {
    $sql .= " AND jp.status = ?";
    $params[] = $statusFilter;
}

if ($typeFilter !== '') {
    $sql .= " AND jp.employment_type = ?";
    $params[] = $typeFilter;
}

if ($deadlineFilter === 'with_deadline') {
    $sql .= " AND jp.deadline IS NOT NULL AND jp.deadline <> ''";
} elseif ($deadlineFilter === 'no_deadline') {
    $sql .= " AND (jp.deadline IS NULL OR jp.deadline = '')";
} elseif ($deadlineFilter === 'expired') {
    $sql .= " AND jp.deadline IS NOT NULL AND jp.deadline <> '' AND jp.deadline < CURDATE()";
} elseif ($deadlineFilter === 'upcoming') {
    $sql .= " AND jp.deadline IS NOT NULL AND jp.deadline <> '' AND jp.deadline >= CURDATE()";
}

$sql .= " ORDER BY jp.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$typeStmt = $pdo->query("SELECT DISTINCT employment_type FROM job_posts WHERE employment_type IS NOT NULL AND employment_type <> '' ORDER BY employment_type ASC");
$employmentTypes = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

$allStatsStmt = $pdo->query(" 
    SELECT
        COUNT(*) AS total_jobs,
        SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) AS open_jobs,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) AS closed_jobs,
        SUM(CASE WHEN deadline IS NOT NULL AND deadline <> '' THEN 1 ELSE 0 END) AS with_deadline,
        SUM(CASE WHEN deadline IS NOT NULL AND deadline <> '' AND deadline < CURDATE() THEN 1 ELSE 0 END) AS expired_jobs
    FROM job_posts
");
$allStats = $allStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

function safeText($value, string $fallback = '—'): string {
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : $fallback;
}

function shortText($value, int $limit = 260): string {
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function jobStatusBadgeClass(string $status): string {
    return match ($status) {
        'Open' => 'job-badge badge-open',
        'Closed' => 'job-badge badge-closed',
        default => 'job-badge badge-neutral'
    };
}

function deadlineMeta($deadline): array {
    if (empty($deadline)) {
        return ['No deadline', 'deadline-neutral'];
    }

    $today = new DateTime('today');
    $date = new DateTime($deadline);
    $diff = (int) $today->diff($date)->format('%r%a');

    if ($diff < 0) {
        return ['Expired ' . abs($diff) . ' day(s) ago', 'deadline-danger'];
    }

    if ($diff === 0) {
        return ['Deadline today', 'deadline-warning'];
    }

    if ($diff <= 7) {
        return [$diff . ' day(s) left', 'deadline-warning'];
    }

    return [date('M j, Y', strtotime($deadline)), 'deadline-good'];
}

$pageTitle = 'Job Post Operations';
$pageSubtitle = 'Review, monitor, and control job opportunities submitted by industry partners.';
$activePage = 'jobs';

require_once '../includes/dashboard_header.php';
?>

<style>
    .admin-command-hero {
        background: radial-gradient(circle at top right, rgba(255,255,255,0.14), transparent 32%),
                    linear-gradient(135deg, #4b0000 0%, #800000 50%, #2b0000 100%);
        color: #fff;
        border-radius: 28px;
        padding: 30px;
        box-shadow: 0 22px 50px rgba(80, 0, 0, 0.22);
        position: relative;
        overflow: hidden;
        margin-bottom: 24px;
    }

    .admin-command-hero::after {
        content: "";
        position: absolute;
        right: -70px;
        bottom: -90px;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.08);
    }

    .admin-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255,255,255,0.14);
        border: 1px solid rgba(255,255,255,0.18);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .admin-hero-title {
        font-size: clamp(30px, 4vw, 46px);
        font-weight: 900;
        line-height: 1.05;
        margin: 18px 0 12px;
        letter-spacing: -1px;
    }

    .admin-hero-text {
        color: rgba(255,255,255,0.78);
        max-width: 760px;
        font-size: 15px;
        line-height: 1.7;
    }

    .admin-hero-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 22px;
    }

    .admin-btn-white,
    .admin-btn-ghost,
    .admin-btn-maroon,
    .admin-btn-danger,
    .admin-btn-soft {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 14px;
        padding: 11px 16px;
        text-decoration: none;
        font-weight: 800;
        font-size: 13px;
        border: 0;
        transition: .2s ease;
        cursor: pointer;
        white-space: nowrap;
    }

    .admin-btn-white {
        background: #fff;
        color: #700000;
        box-shadow: 0 10px 24px rgba(0,0,0,.12);
    }

    .admin-btn-white:hover {
        transform: translateY(-1px);
        color: #700000;
    }

    .admin-btn-ghost {
        background: rgba(255,255,255,0.12);
        color: #fff;
        border: 1px solid rgba(255,255,255,0.22);
    }

    .admin-btn-ghost:hover {
        background: rgba(255,255,255,0.18);
        color: #fff;
    }

    .admin-btn-maroon {
        background: #800000;
        color: #fff;
        box-shadow: 0 10px 20px rgba(128,0,0,.16);
    }

    .admin-btn-maroon:hover {
        background: #660000;
        color: #fff;
    }

    .admin-btn-danger {
        background: #fff1f1;
        color: #b42318;
        border: 1px solid #ffd1d1;
    }

    .admin-btn-danger:hover {
        background: #ffe3e3;
        color: #991b1b;
    }

    .admin-btn-soft {
        background: #fff7f7;
        color: #800000;
        border: 1px solid #f0d8d8;
    }

    .admin-btn-soft:hover {
        background: #ffeded;
        color: #700000;
    }

    .ops-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .ops-card {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 16px 36px rgba(56, 0, 0, 0.06);
        position: relative;
        overflow: hidden;
    }

    .ops-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #800000, #b42318);
    }

    .ops-label {
        color: #8a7070;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
        font-weight: 900;
        margin-bottom: 8px;
    }

    .ops-value {
        color: #2c1717;
        font-size: 30px;
        font-weight: 950;
        line-height: 1;
    }

    .ops-note {
        color: #8b7777;
        font-size: 12px;
        margin-top: 8px;
    }

    .control-panel {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 24px;
        padding: 20px;
        box-shadow: 0 18px 38px rgba(56, 0, 0, 0.06);
        margin-bottom: 22px;
    }

    .control-title {
        color: #2c1717;
        font-size: 16px;
        font-weight: 900;
        margin-bottom: 4px;
    }

    .control-subtitle {
        color: #876f6f;
        font-size: 13px;
        margin-bottom: 16px;
    }

    .form-control,
    .form-select {
        border-radius: 14px;
        border-color: #ead8d8;
        padding: 11px 13px;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #800000;
        box-shadow: 0 0 0 .2rem rgba(128, 0, 0, .12);
    }

    .job-board {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .job-card-admin {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 24px;
        padding: 20px;
        box-shadow: 0 18px 42px rgba(56, 0, 0, 0.06);
        transition: .2s ease;
        position: relative;
        overflow: hidden;
    }

    .job-card-admin:hover {
        transform: translateY(-2px);
        box-shadow: 0 22px 46px rgba(56, 0, 0, 0.10);
    }

    .job-card-admin.open::before,
    .job-card-admin.closed::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 5px;
    }

    .job-card-admin.open::before {
        background: linear-gradient(90deg, #16a34a, #22c55e);
    }

    .job-card-admin.closed::before {
        background: linear-gradient(90deg, #b42318, #ef4444);
    }

    .job-topline {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 14px;
    }

    .job-title-admin {
        color: #2b1717;
        font-size: 18px;
        font-weight: 950;
        line-height: 1.25;
        margin: 0;
    }

    .company-line {
        color: #796464;
        font-size: 13px;
        margin-top: 6px;
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    .job-badge,
    .deadline-pill,
    .mini-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
    }

    .badge-open {
        background: #ecfdf3;
        color: #027a48;
        border: 1px solid #abefc6;
    }

    .badge-closed {
        background: #fff1f3;
        color: #b42318;
        border: 1px solid #fecdd3;
    }

    .badge-neutral {
        background: #f7f5f5;
        color: #6b5555;
        border: 1px solid #e8dddd;
    }

    .mini-pill {
        background: #fff7f7;
        color: #7a2222;
        border: 1px solid #f1dada;
    }

    .deadline-good {
        background: #f0fdf4;
        color: #027a48;
        border: 1px solid #bbf7d0;
    }

    .deadline-warning {
        background: #fffbeb;
        color: #b45309;
        border: 1px solid #fde68a;
    }

    .deadline-danger {
        background: #fff1f1;
        color: #b42318;
        border: 1px solid #fecaca;
    }

    .deadline-neutral {
        background: #f7f5f5;
        color: #6b5555;
        border: 1px solid #e8dddd;
    }

    .job-meta-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin: 16px 0;
    }

    .job-meta-item {
        background: #fffafa;
        border: 1px solid #f1e0e0;
        border-radius: 16px;
        padding: 11px 12px;
    }

    .job-meta-key {
        color: #9a8181;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .07em;
        margin-bottom: 4px;
    }

    .job-meta-value {
        color: #3c2929;
        font-size: 13px;
        font-weight: 800;
    }

    .job-preview {
        background: #fbf7f7;
        border: 1px solid #f0dddd;
        border-radius: 18px;
        padding: 14px;
        color: #665454;
        font-size: 13px;
        line-height: 1.7;
        margin-top: 12px;
    }

    .job-preview strong {
        color: #331d1d;
        display: block;
        margin-bottom: 4px;
    }

    .job-actions-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid #f1e3e3;
    }

    .empty-state-admin {
        background: #fff;
        border: 1px dashed #d9bcbc;
        border-radius: 24px;
        padding: 42px 24px;
        text-align: center;
        color: #7a6464;
    }

    .empty-icon-admin {
        width: 70px;
        height: 70px;
        border-radius: 22px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fff1f1;
        color: #800000;
        font-size: 30px;
        margin-bottom: 14px;
        font-weight: 950;
    }

    @media (max-width: 1200px) {
        .ops-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 992px) {
        .job-board {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .admin-command-hero {
            padding: 24px;
            border-radius: 22px;
        }

        .ops-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .job-meta-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 520px) {
        .ops-grid {
            grid-template-columns: 1fr;
        }

        .job-topline {
            flex-direction: column;
        }
    }
</style>

<div class="admin-command-hero fade-in">
    <div class="position-relative" style="z-index:2;">
        <span class="admin-kicker">Job Operations Center</span>
        <h1 class="admin-hero-title">Manage Partner Job Posts</h1>
        <p class="admin-hero-text">
            Monitor opportunities submitted by industry partners, review posting quality, control visibility,
            and keep alumni-facing job listings clean and reliable.
        </p>
        <div class="admin-hero-actions">
            <a href="partner_accounts.php" class="admin-btn-white">Partner Accounts</a>
            <a href="applications.php" class="admin-btn-ghost">Application Records</a>
            <a href="dashboard.php" class="admin-btn-ghost">Admin Dashboard</a>
        </div>
    </div>
</div>

<div class="ops-grid fade-in">
    <div class="ops-card">
        <div class="ops-label">Total Posts</div>
        <div class="ops-value"><?= (int) ($allStats['total_jobs'] ?? 0) ?></div>
        <div class="ops-note">All partner submitted jobs</div>
    </div>

    <div class="ops-card">
        <div class="ops-label">Open Jobs</div>
        <div class="ops-value"><?= (int) ($allStats['open_jobs'] ?? 0) ?></div>
        <div class="ops-note">Visible to alumni applicants</div>
    </div>

    <div class="ops-card">
        <div class="ops-label">Closed Jobs</div>
        <div class="ops-value"><?= (int) ($allStats['closed_jobs'] ?? 0) ?></div>
        <div class="ops-note">Not accepting applications</div>
    </div>

    <div class="ops-card">
        <div class="ops-label">With Deadline</div>
        <div class="ops-value"><?= (int) ($allStats['with_deadline'] ?? 0) ?></div>
        <div class="ops-note">Time-sensitive postings</div>
    </div>

    <div class="ops-card">
        <div class="ops-label">Expired</div>
        <div class="ops-value"><?= (int) ($allStats['expired_jobs'] ?? 0) ?></div>
        <div class="ops-note">Past deadline jobs</div>
    </div>
</div>

<div class="control-panel fade-in">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
        <div>
            <div class="control-title">Search and Filter Job Posts</div>
            <div class="control-subtitle">Showing <?= count($jobs) ?> result(s) based on the current filters.</div>
        </div>
        <?php if ($search !== '' || $statusFilter !== '' || $typeFilter !== '' || $deadlineFilter !== ''): ?>
            <a href="job_posts.php" class="admin-btn-soft">Clear Filters</a>
        <?php endif; ?>
    </div>

    <form method="GET" action="">
        <div class="row g-3 align-items-end">
            <div class="col-lg-5">
                <label class="form-label">Search</label>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Search title, company, industry, location, requirements..."
                    value="<?= htmlspecialchars($search) ?>"
                >
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
                <label class="form-label">Employment Type</label>
                <select name="type" class="form-select">
                    <option value="">All Types</option>
                    <?php foreach ($employmentTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>>
                            <?= htmlspecialchars($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-4">
                <label class="form-label">Deadline</label>
                <select name="deadline" class="form-select">
                    <option value="">All Deadlines</option>
                    <option value="upcoming" <?= $deadlineFilter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    <option value="expired" <?= $deadlineFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="with_deadline" <?= $deadlineFilter === 'with_deadline' ? 'selected' : '' ?>>With Deadline</option>
                    <option value="no_deadline" <?= $deadlineFilter === 'no_deadline' ? 'selected' : '' ?>>No Deadline</option>
                </select>
            </div>

            <div class="col-lg-1 d-grid">
                <button type="submit" class="admin-btn-maroon">Apply</button>
            </div>
        </div>
    </form>
</div>

<?php if (empty($jobs)): ?>
    <div class="empty-state-admin fade-in">
        <div class="empty-icon-admin">JB</div>
        <h4 class="mb-2" style="color:#351c1c;font-weight:900;">No job posts found</h4>
        <p class="mb-3">Try changing your search keyword or clearing the filters to view more job postings.</p>
        <a href="job_posts.php" class="admin-btn-maroon">Reset View</a>
    </div>
<?php else: ?>
    <div class="job-board fade-in">
        <?php foreach ($jobs as $row): ?>
            <?php
                $currentStatus = $row['status'] ?? 'Closed';
                $toggleTo = $currentStatus === 'Open' ? 'Closed' : 'Open';
                $toggleLabel = $currentStatus === 'Open' ? 'Close Job' : 'Open Job';
                $partnerName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                [$deadlineText, $deadlineClass] = deadlineMeta($row['deadline'] ?? null);
                $cardClass = $currentStatus === 'Open' ? 'open' : 'closed';
            ?>

            <article class="job-card-admin <?= $cardClass ?>">
                <div class="job-topline">
                    <div>
                        <h2 class="job-title-admin"><?= htmlspecialchars(safeText($row['title'] ?? '', 'Untitled Job')) ?></h2>
                        <div class="company-line">
                            <span><?= htmlspecialchars(safeText($row['company_name'] ?? '', 'No Company Name')) ?></span>
                            <?php if (!empty($row['industry_type'])): ?>
                                <span class="mini-pill"><?= htmlspecialchars($row['industry_type']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <span class="<?= jobStatusBadgeClass($currentStatus) ?>">
                        <?= htmlspecialchars($currentStatus) ?>
                    </span>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <span class="mini-pill"><?= htmlspecialchars(safeText($row['employment_type'] ?? '', 'Type not set')) ?></span>
                    <span class="deadline-pill <?= $deadlineClass ?>"><?= htmlspecialchars($deadlineText) ?></span>
                </div>

                <div class="job-meta-grid">
                    <div class="job-meta-item">
                        <div class="job-meta-key">Posted By</div>
                        <div class="job-meta-value"><?= htmlspecialchars($partnerName !== '' ? $partnerName : 'Unknown Partner') ?></div>
                    </div>

                    <div class="job-meta-item">
                        <div class="job-meta-key">Location</div>
                        <div class="job-meta-value"><?= htmlspecialchars(safeText($row['location'] ?? '')) ?></div>
                    </div>

                    <div class="job-meta-item">
                        <div class="job-meta-key">Salary Range</div>
                        <div class="job-meta-value"><?= htmlspecialchars(safeText($row['salary_range'] ?? '')) ?></div>
                    </div>

                    <div class="job-meta-item">
                        <div class="job-meta-key">Created On</div>
                        <div class="job-meta-value"><?= !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : '—' ?></div>
                    </div>
                </div>

                <?php if (!empty($row['description'])): ?>
                    <div class="job-preview">
                        <strong>Description</strong>
                        <?= nl2br(htmlspecialchars(shortText($row['description'], 280))) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($row['requirements'])): ?>
                    <div class="job-preview">
                        <strong>Requirements</strong>
                        <?= nl2br(htmlspecialchars(shortText($row['requirements'], 240))) ?>
                    </div>
                <?php endif; ?>

                <div class="job-actions-row">
                    <a href="update_job_status.php?id=<?= (int) $row['id'] ?>&status=<?= urlencode($toggleTo) ?>"
                       class="admin-btn-soft"
                       onclick="return confirm('Are you sure you want to <?= strtolower($toggleLabel) ?>?');">
                        <?= htmlspecialchars($toggleLabel) ?>
                    </a>

                    <a href="delete_job.php?id=<?= (int) $row['id'] ?>"
                       class="admin-btn-danger"
                       onclick="return confirm('Are you sure you want to delete this job post?');">
                        Delete
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once '../includes/dashboard_footer.php'; ?>
