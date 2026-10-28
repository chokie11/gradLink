<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';
require_once '../includes/course_work_match.php';

requireRole(['admin']);

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$courseFilter = trim($_GET['course'] ?? '');
$yearFilter = trim($_GET['graduation_year'] ?? '');
$completionFilter = trim($_GET['completion'] ?? '');

/*
|--------------------------------------------------------------------------
| Course label mapping
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function profileCompletion(array $row): int
{
    $fields = [
        $row['student_id'] ?? '',
        $row['course'] ?? '',
        $row['graduation_year'] ?? '',
        $row['phone'] ?? '',
        $row['address'] ?? ''
    ];

    $coursesWithMajor = ['BSEd', 'BPEd', 'BTLEd', 'BSBA', 'BSIndTech'];
    if (in_array(($row['course'] ?? ''), $coursesWithMajor, true)) {
        $fields[] = $row['major'] ?? '';
    }

    $filled = 0;
    foreach ($fields as $field) {
        if (!empty(trim((string) $field))) {
            $filled++;
        }
    }

    return count($fields) > 0 ? (int) round(($filled / count($fields)) * 100) : 0;
}

function statusBadgeClass(string $status): string
{
    return match (strtolower($status)) {
        'active' => 'status-pill status-active',
        'inactive' => 'status-pill status-inactive',
        'pending' => 'status-pill status-pending',
        default => 'status-pill status-neutral'
    };
}

function completionTone(int $completion): string
{
    if ($completion >= 100) {
        return 'complete';
    }
    if ($completion >= 75) {
        return 'good';
    }
    if ($completion >= 50) {
        return 'warning';
    }
    return 'danger';
}

function displayValue($value): string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : '—';
}

/*
|--------------------------------------------------------------------------
| Filter options
|--------------------------------------------------------------------------
*/
$courseOptionsStmt = $pdo->query("
    SELECT DISTINCT ap.course
    FROM users u
    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
    WHERE u.role = 'alumni'
      AND ap.course IS NOT NULL
      AND ap.course <> ''
    ORDER BY ap.course ASC
");
$courseOptions = $courseOptionsStmt->fetchAll(PDO::FETCH_COLUMN);

$yearOptionsStmt = $pdo->query("
    SELECT DISTINCT ap.graduation_year
    FROM users u
    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
    WHERE u.role = 'alumni'
      AND ap.graduation_year IS NOT NULL
      AND ap.graduation_year <> ''
    ORDER BY ap.graduation_year DESC
");
$yearOptions = $yearOptionsStmt->fetchAll(PDO::FETCH_COLUMN);

/*
|--------------------------------------------------------------------------
| Load all alumni for global counters
|--------------------------------------------------------------------------
*/
$baseSelect = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.status,
        u.created_at,
        ap.student_id,
        ap.course,
        ap.major,
        ap.graduation_year,
        ap.phone,
        ap.address,
        ap.employment_status,
        ap.company_name,
        ap.job_position,
        ap.industry_type,
        ap.company_location,
        ap.work_experience
    FROM users u
    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
    WHERE u.role = 'alumni'
";

$allStmt = $pdo->prepare($baseSelect . " ORDER BY u.created_at DESC");
$allStmt->execute();
$allAlumni = $allStmt->fetchAll(PDO::FETCH_ASSOC);

$totalAlumni = count($allAlumni);
$totalActive = count(array_filter($allAlumni, fn($row) => strtolower((string) ($row['status'] ?? '')) === 'active'));
$totalInactive = count(array_filter($allAlumni, fn($row) => strtolower((string) ($row['status'] ?? '')) === 'inactive'));
$totalPending = count(array_filter($allAlumni, fn($row) => strtolower((string) ($row['status'] ?? '')) === 'pending'));
$totalComplete = count(array_filter($allAlumni, fn($row) => profileCompletion($row) === 100));
$averageCompletion = $totalAlumni > 0
    ? (int) round(array_sum(array_map(fn($row) => profileCompletion($row), $allAlumni)) / $totalAlumni)
    : 0;

/*
|--------------------------------------------------------------------------
| Load filtered alumni accounts
|--------------------------------------------------------------------------
*/
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        u.first_name LIKE ?
        OR u.last_name LIKE ?
        OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
        OR u.email LIKE ?
        OR ap.student_id LIKE ?
        OR ap.course LIKE ?
        OR ap.major LIKE ?
        OR ap.phone LIKE ?
    )";

    $searchLike = '%' . $search . '%';
    $params = array_merge($params, [$searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike]);
}

if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive', 'pending'], true)) {
    $where[] = "u.status = ?";
    $params[] = $statusFilter;
}

if ($courseFilter !== '') {
    $where[] = "ap.course = ?";
    $params[] = $courseFilter;
}

if ($yearFilter !== '') {
    $where[] = "ap.graduation_year = ?";
    $params[] = $yearFilter;
}

$sql = $baseSelect;
if (!empty($where)) {
    $sql .= " AND " . implode(" AND ", $where);
}
$sql .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alumni = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($completionFilter !== '') {
    $alumni = array_values(array_filter($alumni, function ($row) use ($completionFilter) {
        $completion = profileCompletion($row);

        return match ($completionFilter) {
            'complete' => $completion === 100,
            'incomplete' => $completion < 100,
            'low' => $completion < 50,
            default => true
        };
    }));
}

$filteredCount = count($alumni);
$filteredActive = count(array_filter($alumni, fn($row) => strtolower((string) ($row['status'] ?? '')) === 'active'));
$filteredComplete = count(array_filter($alumni, fn($row) => profileCompletion($row) === 100));
$filteredAverageCompletion = $filteredCount > 0
    ? (int) round(array_sum(array_map(fn($row) => profileCompletion($row), $alumni)) / $filteredCount)
    : 0;

$pageTitle = 'Alumni Accounts';
$pageSubtitle = 'Monitor alumni profiles, account access, and readiness for tracer and career services.';
$activePage = 'alumni';

require_once '../includes/dashboard_header.php';
?>

<style>
    .admin-console-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 28px;
        color: #fff;
        background:
            radial-gradient(circle at top right, rgba(255, 214, 214, 0.22), transparent 28%),
            linear-gradient(135deg, #3b0508 0%, #800000 48%, #a3131b 100%);
        box-shadow: 0 22px 50px rgba(70, 0, 0, 0.22);
        margin-bottom: 22px;
    }

    .admin-console-hero::after {
        content: "";
        position: absolute;
        width: 280px;
        height: 280px;
        right: -90px;
        bottom: -120px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.11);
    }

    .admin-console-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: 1.4fr 0.9fr;
        gap: 22px;
        align-items: stretch;
    }

    .console-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.2);
        font-weight: 800;
        font-size: 12px;
        letter-spacing: 0.8px;
        text-transform: uppercase;
    }

    .console-title {
        font-size: clamp(30px, 4vw, 48px);
        line-height: 1.02;
        font-weight: 900;
        letter-spacing: -1px;
        margin: 16px 0 10px;
    }

    .console-copy {
        max-width: 760px;
        color: rgba(255, 255, 255, 0.84);
        font-size: 15px;
        line-height: 1.7;
        margin-bottom: 18px;
    }

    .console-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .console-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        min-height: 42px;
        padding: 10px 16px;
        border-radius: 14px;
        text-decoration: none;
        font-weight: 800;
        transition: 0.2s ease;
        border: 1px solid rgba(255, 255, 255, 0.22);
    }

    .console-btn-light {
        background: #fff;
        color: #800000;
    }

    .console-btn-ghost {
        color: #fff;
        background: rgba(255, 255, 255, 0.12);
    }

    .console-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 16px 28px rgba(40, 0, 0, 0.22);
    }

    .hero-metric-panel {
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 24px;
        padding: 20px;
        backdrop-filter: blur(8px);
    }

    .hero-metric-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .hero-metric {
        padding: 16px;
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.12);
    }

    .hero-metric .label {
        color: rgba(255, 255, 255, 0.72);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.6px;
    }

    .hero-metric .value {
        font-size: 30px;
        font-weight: 900;
        margin-top: 5px;
    }

    .ops-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 22px;
    }

    .ops-card {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 22px;
        padding: 20px;
        box-shadow: 0 14px 34px rgba(92, 0, 0, 0.07);
        position: relative;
        overflow: hidden;
    }

    .ops-card::before {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 5px;
        background: linear-gradient(180deg, #800000, #c74a4a);
    }

    .ops-label {
        color: #866b6b;
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.7px;
    }

    .ops-value {
        color: #2b1515;
        font-size: 32px;
        font-weight: 950;
        margin: 7px 0 2px;
    }

    .ops-note {
        color: #8c7777;
        font-size: 13px;
    }

    .admin-panel {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 24px;
        box-shadow: 0 16px 36px rgba(92, 0, 0, 0.07);
        padding: 22px;
        margin-bottom: 22px;
    }

    .panel-headline {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .panel-headline h3 {
        margin: 0;
        color: #2b1515;
        font-weight: 900;
        letter-spacing: -0.2px;
    }

    .panel-headline p {
        margin: 4px 0 0;
        color: #8c7777;
        font-size: 14px;
    }

    .filter-box {
        background: #fffafa;
        border: 1px solid #f1dddd;
        border-radius: 18px;
        padding: 16px;
    }

    .form-label {
        font-size: 12px;
        font-weight: 900;
        color: #5a2525;
        text-transform: uppercase;
        letter-spacing: 0.6px;
    }

    .form-control,
    .form-select {
        min-height: 44px;
        border-radius: 14px;
        border-color: #ead4d4;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #800000;
        box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.12);
    }

    .btn-admin-primary {
        border: 0;
        min-height: 44px;
        border-radius: 14px;
        padding: 10px 16px;
        background: linear-gradient(135deg, #800000, #a3131b);
        color: #fff;
        font-weight: 900;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .btn-admin-soft {
        min-height: 44px;
        border-radius: 14px;
        padding: 10px 16px;
        background: #fff4f4;
        color: #800000;
        border: 1px solid #efd2d2;
        font-weight: 900;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .alumni-table-wrap {
        overflow: auto;
        border-radius: 20px;
        border: 1px solid #f0dddd;
    }

    .admin-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        min-width: 1060px;
        background: #fff;
    }

    .admin-table thead th {
        position: sticky;
        top: 0;
        z-index: 1;
        background: #fff7f7;
        color: #6b2b2b;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.7px;
        padding: 15px 16px;
        border-bottom: 1px solid #f0dddd;
        white-space: nowrap;
    }

    .admin-table tbody td {
        padding: 16px;
        border-bottom: 1px solid #f6e7e7;
        vertical-align: middle;
    }

    .admin-table tbody tr:hover {
        background: #fffafa;
    }

    .admin-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .avatar-mini {
        width: 44px;
        height: 44px;
        border-radius: 15px;
        background: linear-gradient(135deg, #800000, #c74a4a);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 950;
        letter-spacing: 0.5px;
        flex: 0 0 44px;
    }

    .identity-cell {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .identity-name {
        color: #2b1515;
        font-weight: 900;
        line-height: 1.2;
    }

    .identity-sub {
        color: #8c7777;
        font-size: 13px;
        margin-top: 4px;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 7px 11px;
        font-size: 12px;
        font-weight: 900;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .status-active {
        background: #ecfdf3;
        color: #128246;
        border-color: #c8f1d8;
    }

    .status-inactive {
        background: #fff0f0;
        color: #b02020;
        border-color: #f5cdcd;
    }

    .status-pending {
        background: #fff8e6;
        color: #9a6500;
        border-color: #f2dfaa;
    }

    .status-neutral {
        background: #f4f4f4;
        color: #666;
        border-color: #e2e2e2;
    }

    .completion-wrap {
        min-width: 140px;
    }

    .completion-top {
        display: flex;
        justify-content: space-between;
        color: #5f4545;
        font-size: 12px;
        font-weight: 900;
        margin-bottom: 7px;
    }

    .completion-track {
        height: 9px;
        border-radius: 999px;
        background: #f5e8e8;
        overflow: hidden;
    }

    .completion-fill {
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #800000, #c74a4a);
    }

    .completion-fill.complete {
        background: linear-gradient(90deg, #15803d, #22c55e);
    }

    .completion-fill.warning {
        background: linear-gradient(90deg, #b45309, #f59e0b);
    }

    .completion-fill.danger {
        background: linear-gradient(90deg, #991b1b, #ef4444);
    }

    .meta-chip {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #fff4f4;
        color: #6e3434;
        border: 1px solid #eed5d5;
        padding: 6px 10px;
        font-weight: 800;
        font-size: 12px;
        margin: 2px 4px 2px 0;
    }

    .action-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 36px;
        padding: 8px 11px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 900;
        text-decoration: none;
        border: 1px solid #ead4d4;
        background: #fff;
        color: #6b2b2b;
        white-space: nowrap;
    }

    .action-btn.primary {
        background: #800000;
        color: #fff;
        border-color: #800000;
    }

    .action-btn.warning {
        background: #fff8e6;
        color: #8a5600;
        border-color: #f0dca6;
    }

    .action-btn.danger {
        background: #fff0f0;
        color: #a91d1d;
        border-color: #f2cccc;
    }

    .empty-state {
        border: 1px dashed #e6c9c9;
        border-radius: 22px;
        padding: 42px 20px;
        text-align: center;
        background: #fffafa;
    }

    .empty-icon {
        width: 64px;
        height: 64px;
        border-radius: 22px;
        margin: 0 auto 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fff0f0;
        color: #800000;
        font-size: 28px;
        font-weight: 900;
    }

    @media (max-width: 1100px) {
        .admin-console-grid,
        .ops-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    @media (max-width: 720px) {
        .admin-console-hero {
            padding: 22px;
            border-radius: 22px;
        }

        .admin-console-grid,
        .ops-grid,
        .hero-metric-grid {
            grid-template-columns: 1fr;
        }

        .action-row {
            justify-content: flex-start;
        }
    }
</style>

<div class="admin-console-hero">
    <div class="admin-console-grid">
        <div>
            <div class="console-kicker">Admin Control Center</div>
            <h1 class="console-title">Alumni Account Operations</h1>
            <p class="console-copy">
                Review alumni access, monitor profile readiness, and prepare alumni records for job matching,
                tracer study participation, and institutional reporting.
            </p>
            <div class="console-actions">
                <a href="surveys.php" class="console-btn console-btn-light">Survey Control Center</a>
                <a href="tracer_dashboard.php" class="console-btn console-btn-ghost">Tracer Dashboard</a>
            </div>
        </div>

        <div class="hero-metric-panel">
            <div class="hero-metric-grid">
                <div class="hero-metric">
                    <div class="label">Total Alumni</div>
                    <div class="value"><?= number_format($totalAlumni) ?></div>
                </div>
                <div class="hero-metric">
                    <div class="label">Active</div>
                    <div class="value"><?= number_format($totalActive) ?></div>
                </div>
                <div class="hero-metric">
                    <div class="label">Complete</div>
                    <div class="value"><?= number_format($totalComplete) ?></div>
                </div>
                <div class="hero-metric">
                    <div class="label">Avg. Profile</div>
                    <div class="value"><?= $averageCompletion ?>%</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="ops-grid">
    <div class="ops-card">
        <div class="ops-label">Filtered Results</div>
        <div class="ops-value"><?= number_format($filteredCount) ?></div>
        <div class="ops-note">Records matching current filters</div>
    </div>
    <div class="ops-card">
        <div class="ops-label">Active in Results</div>
        <div class="ops-value"><?= number_format($filteredActive) ?></div>
        <div class="ops-note">Can currently access the portal</div>
    </div>
    <div class="ops-card">
        <div class="ops-label">Complete Profiles</div>
        <div class="ops-value"><?= number_format($filteredComplete) ?></div>
        <div class="ops-note">100% completion in this view</div>
    </div>
    <div class="ops-card">
        <div class="ops-label">Average Completion</div>
        <div class="ops-value"><?= $filteredAverageCompletion ?>%</div>
        <div class="ops-note">Readiness of filtered alumni</div>
    </div>
</div>

<div class="admin-panel">
    <div class="panel-headline">
        <div>
            <h3>Search & Filter Alumni</h3>
            <p>Use filters to audit accounts by course, batch, access status, or profile completion.</p>
        </div>
        <a href="alumni_accounts.php" class="btn-admin-soft">Reset Filters</a>
    </div>

    <form method="GET" action="" class="filter-box">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4 col-md-6">
                <label class="form-label">Search</label>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Name, email, student ID, phone, course..."
                    value="<?= htmlspecialchars($search) ?>"
                >
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">Course</label>
                <select name="course" class="form-select">
                    <option value="">All Courses</option>
                    <?php foreach ($courseOptions as $course): ?>
                        <option value="<?= htmlspecialchars($course) ?>" <?= $courseFilter === $course ? 'selected' : '' ?>>
                            <?= htmlspecialchars($courseLabels[$course] ?? $course) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">Graduation Year</label>
                <select name="graduation_year" class="form-select">
                    <option value="">All Years</option>
                    <?php foreach ($yearOptions as $year): ?>
                        <option value="<?= htmlspecialchars($year) ?>" <?= (string) $yearFilter === (string) $year ? 'selected' : '' ?>>
                            <?= htmlspecialchars($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">Profile</label>
                <select name="completion" class="form-select">
                    <option value="">All Profiles</option>
                    <option value="complete" <?= $completionFilter === 'complete' ? 'selected' : '' ?>>Complete</option>
                    <option value="incomplete" <?= $completionFilter === 'incomplete' ? 'selected' : '' ?>>Incomplete</option>
                    <option value="low" <?= $completionFilter === 'low' ? 'selected' : '' ?>>Below 50%</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-6 d-grid">
                <button type="submit" class="btn-admin-primary">Apply Filters</button>
            </div>
        </div>
    </form>
</div>

<div class="admin-panel">
    <div class="panel-headline">
        <div>
            <h3>Alumni Directory</h3>
            <p><?= number_format($filteredCount) ?> alumni account<?= $filteredCount === 1 ? '' : 's' ?> found.</p>
        </div>
        <div>
            <span class="meta-chip">Active: <?= number_format($filteredActive) ?></span>
            <span class="meta-chip">Complete: <?= number_format($filteredComplete) ?></span>
        </div>
    </div>

    <?php if (empty($alumni)): ?>
        <div class="empty-state">
            <div class="empty-icon">!</div>
            <h4>No alumni accounts found</h4>
            <p class="text-muted mb-3">Try adjusting your search or clearing some filters.</p>
            <a href="alumni_accounts.php" class="btn-admin-primary">Clear Filters</a>
        </div>
    <?php else: ?>
        <div class="alumni-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Alumni</th>
                        <th>Academic Profile</th>
                        <th>Contact</th>
                        <th>Employment</th>
                        <th>Access</th>
                        <th>Profile</th>
                        <th>Registered</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alumni as $row): ?>
                        <?php
                            $completion = profileCompletion($row);
                            $completionTone = completionTone($completion);
                            $displayCourse = $courseLabels[$row['course'] ?? ''] ?? displayValue($row['course'] ?? '');
                            $currentStatus = strtolower((string) ($row['status'] ?? 'inactive'));
                            $toggleTo = $currentStatus === 'active' ? 'inactive' : 'active';
                            $toggleLabel = $currentStatus === 'active' ? 'Deactivate' : 'Activate';
                            $toggleClass = $currentStatus === 'active' ? 'danger' : 'primary';
                            $initials = strtoupper(substr((string) ($row['first_name'] ?? 'A'), 0, 1) . substr((string) ($row['last_name'] ?? 'L'), 0, 1));
                        ?>
                        <tr>
                            <td>
                                <div class="identity-cell">
                                    <div>
                                        <div class="identity-name">
                                            <?= htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Unnamed Alumni') ?>
                                        </div>
                                        <div class="identity-sub"><?= htmlspecialchars(displayValue($row['student_id'] ?? '')) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="fw-bold" style="color:#3b2020;"><?= htmlspecialchars($displayCourse) ?></div>
                                <div class="text-muted small mt-1">Major: <?= htmlspecialchars(displayValue($row['major'] ?? '')) ?></div>
                                <div class="text-muted small">Batch: <?= htmlspecialchars(displayValue($row['graduation_year'] ?? '')) ?></div>
                            </td>
                            <td>
                                <div class="fw-bold" style="color:#3b2020;"><?= htmlspecialchars(displayValue($row['email'] ?? '')) ?></div>
                                <div class="text-muted small mt-1"><?= htmlspecialchars(displayValue($row['phone'] ?? '')) ?></div>
                            </td>
                            <td>
                                <span class="meta-chip"><?= htmlspecialchars(displayValue($row['employment_status'] ?? '')) ?></span>
                                <?php if (isWorkingStatus((string) ($row['employment_status'] ?? ''))): ?>
                                    <?php
                                        $workFit = classifyWorkAgainstCourse(
                                            (string) ($row['course'] ?? ''),
                                            (string) ($row['job_position'] ?? ''),
                                            trim(($row['company_name'] ?? '') . ' ' . ($row['industry_type'] ?? '') . ' ' . ($row['work_experience'] ?? ''))
                                        );
                                        $fitLabel = ['match' => 'Related to course', 'no_match' => 'Not related to course', 'unknown' => 'No job details yet'][$workFit];
                                        $fitColor = ['match' => '#246b43', 'no_match' => '#b42318', 'unknown' => '#6b7280'][$workFit];
                                    ?>
                                    <?php if (!empty($row['job_position']) || !empty($row['company_name'])): ?>
                                        <div class="fw-bold small mt-2" style="color:#3b2020;"><?= htmlspecialchars(displayValue($row['job_position'] ?? '')) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars(displayValue($row['company_name'] ?? '')) ?></div>
                                        <?php if (!empty($row['industry_type'])): ?><div class="text-muted small"><?= htmlspecialchars($row['industry_type']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['company_location'])): ?><div class="text-muted small"><?= htmlspecialchars($row['company_location']) ?></div><?php endif; ?>
                                    <?php endif; ?>
                                    <div class="small fw-bold mt-1" style="color:<?= $fitColor ?>;">&#9679; <?= $fitLabel ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?= statusBadgeClass($currentStatus) ?>">
                                    <?= htmlspecialchars(ucfirst($currentStatus ?: 'Inactive')) ?>
                                </span>
                            </td>
                            <td>
                                <div class="completion-wrap">
                                    <div class="completion-top">
                                        <span>Completion</span>
                                        <span><?= $completion ?>%</span>
                                    </div>
                                    <div class="completion-track">
                                        <div class="completion-fill <?= htmlspecialchars($completionTone) ?>" style="width: <?= $completion ?>%"></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="fw-bold" style="color:#3b2020;">
                                    <?= !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : '—' ?>
                                </div>
                                <div class="text-muted small">
                                    <?= !empty($row['created_at']) ? date('g:i A', strtotime($row['created_at'])) : '' ?>
                                </div>
                            </td>
                            <td>
                                <div class="action-row">
                                    <a href="alumni_career_timeline.php?id=<?= (int) $row['id'] ?>" class="action-btn">
                                        Timeline
                                    </a>
                                    <a href="update_user_status.php?id=<?= (int) $row['id'] ?>&status=<?= htmlspecialchars($toggleTo) ?>&role=alumni"
                                       class="action-btn <?= htmlspecialchars($toggleClass) ?>"
                                       onclick="return confirm('Are you sure you want to <?= strtolower($toggleLabel) ?> this alumni account?');">
                                        <?= htmlspecialchars($toggleLabel) ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
