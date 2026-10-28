<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';
require_once '../includes/course_work_match.php';

requireRole(['admin']);

/*
|--------------------------------------------------------------------------
| Core platform counts
|--------------------------------------------------------------------------
*/
$userCountsStmt = $pdo->query("
    SELECT role, COUNT(*) AS total
    FROM users
    GROUP BY role
");
$userCountsRaw = $userCountsStmt->fetchAll(PDO::FETCH_ASSOC);

$userCounts = [
    'admin' => 0,
    'alumni' => 0,
    'partner' => 0
];

foreach ($userCountsRaw as $row) {
    $userCounts[$row['role']] = (int) $row['total'];
}

$totalUsers = array_sum($userCounts);

$userStatusStmt = $pdo->query("
    SELECT status, COUNT(*) AS total
    FROM users
    GROUP BY status
");
$userStatusRaw = $userStatusStmt->fetchAll(PDO::FETCH_ASSOC);

$userStatusCounts = [
    'active' => 0,
    'pending' => 0,
    'inactive' => 0
];

foreach ($userStatusRaw as $row) {
    $userStatusCounts[$row['status']] = (int) $row['total'];
}

/*
|--------------------------------------------------------------------------
| Jobs and applications
|--------------------------------------------------------------------------
*/
$jobCountsStmt = $pdo->query("
    SELECT status, COUNT(*) AS total
    FROM job_posts
    GROUP BY status
");
$jobCountsRaw = $jobCountsStmt->fetchAll(PDO::FETCH_ASSOC);

$jobCounts = [
    'Open' => 0,
    'Closed' => 0
];

foreach ($jobCountsRaw as $row) {
    $jobCounts[$row['status']] = (int) $row['total'];
}

$totalJobPosts = array_sum($jobCounts);

$appCountsStmt = $pdo->query("
    SELECT status, COUNT(*) AS total
    FROM applications
    GROUP BY status
");
$appCountsRaw = $appCountsStmt->fetchAll(PDO::FETCH_ASSOC);

$appCounts = [
    'Pending' => 0,
    'Under Review' => 0,
    'Reviewed' => 0,
    'Interview Scheduled' => 0,
    'Offered' => 0,
    'Hired' => 0,
    'Not Selected' => 0,
    'Rejected' => 0
];

foreach ($appCountsRaw as $row) {
    $appCounts[$row['status']] = (int) $row['total'];
}

$totalApplications = array_sum($appCounts);
$pendingApplications = ($appCounts['Pending'] ?? 0) + ($appCounts['Under Review'] ?? 0);
$successfulApplications = ($appCounts['Hired'] ?? 0) + ($appCounts['Offered'] ?? 0);

/*
|--------------------------------------------------------------------------
| Announcements and surveys
|--------------------------------------------------------------------------
*/
$announcementStatsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft
    FROM announcements
");
$announcementStats = $announcementStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'published' => 0,
    'draft' => 0
];

$surveyStatsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_surveys,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_surveys,
        SUM(CASE WHEN survey_type = 'tracer' THEN 1 ELSE 0 END) AS tracer_surveys,
        SUM(CASE WHEN survey_type = 'general' OR survey_type IS NULL THEN 1 ELSE 0 END) AS general_surveys
    FROM tracer_surveys
");
$surveyStats = $surveyStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_surveys' => 0,
    'active_surveys' => 0,
    'tracer_surveys' => 0,
    'general_surveys' => 0
];

/*
|--------------------------------------------------------------------------
| Tracer study summary
|--------------------------------------------------------------------------
*/
$activeTracerStmt = $pdo->query("
    SELECT id, title, description, created_at
    FROM tracer_surveys
    WHERE survey_type = 'tracer'
      AND is_active = 1
    ORDER BY id DESC
    LIMIT 1
");
$activeTracerSurvey = $activeTracerStmt->fetch(PDO::FETCH_ASSOC);

$tracerParticipants = 0;
$totalCareerUpdates = 0;
$latestCareerUpdate = null;
$employmentBreakdown = [];
$employmentRate = 0;
$activeTracerResponses = 0;
$activeTracerLatestResponses = 0;

if ($activeTracerSurvey) {
    $tracerStatsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_updates,
            COUNT(DISTINCT alumni_id) AS total_participants,
            SUM(CASE WHEN is_latest = 1 THEN 1 ELSE 0 END) AS latest_records,
            MAX(submitted_at) AS latest_update
        FROM tracer_responses
        WHERE survey_id = ?
    ");
    $tracerStatsStmt->execute([$activeTracerSurvey['id']]);
    $tracerStats = $tracerStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalCareerUpdates = (int) ($tracerStats['total_updates'] ?? 0);
    $tracerParticipants = (int) ($tracerStats['total_participants'] ?? 0);
    $activeTracerResponses = $totalCareerUpdates;
    $activeTracerLatestResponses = (int) ($tracerStats['latest_records'] ?? 0);
    $latestCareerUpdate = $tracerStats['latest_update'] ?? null;

    $employmentStmt = $pdo->prepare("
        SELECT TRIM(a.answer_text) AS employment_status, COUNT(*) AS total
        FROM tracer_responses r
        INNER JOIN (
            SELECT alumni_id, MAX(id) AS latest_id
            FROM tracer_responses
            WHERE survey_id = ?
            GROUP BY alumni_id
        ) latest ON latest.latest_id = r.id
        INNER JOIN tracer_answers a ON a.response_id = r.id
        INNER JOIN tracer_questions q ON q.id = a.question_id
        WHERE r.survey_id = ?
          AND (
              LOWER(q.question_text) LIKE '%employment status%'
              OR LOWER(q.question_text) LIKE '%status of employment%'
          )
          AND TRIM(a.answer_text) <> ''
        GROUP BY TRIM(a.answer_text)
        ORDER BY total DESC
    ");
    $employmentStmt->execute([$activeTracerSurvey['id'], $activeTracerSurvey['id']]);
    $employmentBreakdown = $employmentStmt->fetchAll(PDO::FETCH_ASSOC);

    $employedCount = 0;
    $currentStatusTotal = 0;
    foreach ($employmentBreakdown as $item) {
        $status = strtolower((string) $item['employment_status']);
        $count = (int) $item['total'];
        $currentStatusTotal += $count;

        if (str_contains($status, 'employed') || str_contains($status, 'self') || str_contains($status, 'freelance')) {
            if (!str_contains($status, 'unemployed')) {
                $employedCount += $count;
            }
        }
    }

    $employmentRate = $currentStatusTotal > 0 ? round(($employedCount / $currentStatusTotal) * 100) : 0;
}

$workAlignment = getWorkCourseAlignment($pdo);
$alignRate = $workAlignment['working'] > 0 ? round(($workAlignment['match'] / $workAlignment['working']) * 100) : 0;

$tracerParticipationRate = $userCounts['alumni'] > 0 ? round(($tracerParticipants / $userCounts['alumni']) * 100) : 0;

/*
|--------------------------------------------------------------------------
| Recent activity panels
|--------------------------------------------------------------------------
*/
$recentUsersStmt = $pdo->query("
    SELECT first_name, last_name, email, role, status, created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 6
");
$recentUsers = $recentUsersStmt->fetchAll(PDO::FETCH_ASSOC);

$recentJobsStmt = $pdo->query("
    SELECT jp.id, jp.title, jp.status, jp.created_at, jp.deadline, pp.company_name
    FROM job_posts jp
    LEFT JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    ORDER BY jp.created_at DESC
    LIMIT 6
");
$recentJobs = $recentJobsStmt->fetchAll(PDO::FETCH_ASSOC);

$recentApplicationsStmt = $pdo->query("
    SELECT a.id, a.status, a.applied_at, a.applicant_name, jp.title AS job_title, pp.company_name
    FROM applications a
    LEFT JOIN job_posts jp ON a.job_post_id = jp.id
    LEFT JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    ORDER BY a.applied_at DESC
    LIMIT 6
");
$recentApplications = $recentApplicationsStmt->fetchAll(PDO::FETCH_ASSOC);

$latestTracerUpdates = [];
if ($activeTracerSurvey) {
    $latestTracerStmt = $pdo->prepare("
        SELECT r.id, r.alumni_id, r.submitted_at, u.first_name, u.last_name, ap.course, ap.graduation_year
        FROM tracer_responses r
        INNER JOIN users u ON r.alumni_id = u.id
        LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
        WHERE r.survey_id = ?
        ORDER BY r.submitted_at DESC, r.id DESC
        LIMIT 5
    ");
    $latestTracerStmt->execute([$activeTracerSurvey['id']]);
    $latestTracerUpdates = $latestTracerStmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function adminBadgeClass(string $value): string
{
    return match ($value) {
        'active', 'Open', 'Hired', 'Offered', 'published' => 'badge-good',
        'pending', 'Pending', 'Under Review', 'Interview Scheduled', 'Reviewed', 'draft' => 'badge-warn',
        'inactive', 'Closed', 'Not Selected', 'Rejected' => 'badge-danger-soft',
        'admin' => 'badge-dark-soft',
        'alumni' => 'badge-maroon-soft',
        'partner' => 'badge-gold-soft',
        default => 'badge-neutral'
    };
}

function formatShortDate(?string $date): string
{
    if (empty($date)) {
        return '—';
    }

    return date('M j, Y', strtotime($date));
}

function formatDateTimeShort(?string $date): string
{
    if (empty($date)) {
        return '—';
    }

    return date('M j, Y g:i A', strtotime($date));
}

$pageTitle = 'Admin Command Center';
$pageSubtitle = 'Operational overview for users, tracer study, recruitment, and platform activity.';
$activePage = 'dashboard';

require_once '../includes/dashboard_header.php';
?>

<style>
    :root {
        --admin-maroon: #800000;
        --admin-maroon-dark: #4d0000;
        --admin-maroon-soft: #fff3f3;
        --admin-ink: #171923;
        --admin-muted: #6b7280;
        --admin-border: #eee2e2;
        --admin-bg: #f8f4f4;
        --admin-gold: #c8942e;
        --admin-panel: #ffffff;
    }

    .admin-shell {
        position: relative;
    }

    .admin-command-hero {
        position: relative;
        overflow: hidden;
        border-radius: 30px;
        background:
            radial-gradient(circle at 86% 18%, rgba(255, 255, 255, 0.26), transparent 24%),
            radial-gradient(circle at 14% 82%, rgba(200, 148, 46, 0.28), transparent 22%),
            linear-gradient(135deg, #210202 0%, #640000 48%, #960b0b 100%);
        color: #ffffff;
        padding: 30px;
        box-shadow: 0 24px 60px rgba(80, 0, 0, 0.22);
        border: 1px solid rgba(255, 255, 255, 0.14);
    }

    .admin-command-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(255,255,255,0.06) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,0.06) 1px, transparent 1px);
        background-size: 38px 38px;
        mask-image: linear-gradient(to bottom right, rgba(0,0,0,0.8), transparent 78%);
        pointer-events: none;
    }

    .admin-command-hero > * {
        position: relative;
        z-index: 1;
    }

    .admin-hero-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.6fr);
        gap: 24px;
        align-items: stretch;
    }

    .admin-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.18);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 1px;
        text-transform: uppercase;
    }

    .admin-hero-title {
        margin: 18px 0 10px;
        font-size: clamp(32px, 4vw, 52px);
        font-weight: 900;
        letter-spacing: -1.8px;
        line-height: 1.02;
    }

    .admin-hero-text {
        max-width: 760px;
        color: rgba(255, 255, 255, 0.82);
        font-size: 15px;
        line-height: 1.7;
    }

    .admin-hero-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 24px;
    }

    .admin-btn-primary,
    .admin-btn-ghost,
    .admin-btn-white {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        padding: 12px 16px;
        border-radius: 15px;
        text-decoration: none;
        font-weight: 800;
        font-size: 14px;
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .admin-btn-white {
        background: #ffffff;
        color: var(--admin-maroon);
        box-shadow: 0 14px 28px rgba(0, 0, 0, 0.18);
    }

    .admin-btn-primary {
        background: linear-gradient(135deg, var(--admin-maroon), var(--admin-maroon-dark));
        color: #ffffff;
        box-shadow: 0 16px 28px rgba(128, 0, 0, 0.22);
    }

    .admin-btn-ghost {
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.26);
        background: rgba(255, 255, 255, 0.09);
    }

    .admin-btn-white:hover,
    .admin-btn-primary:hover,
    .admin-btn-ghost:hover {
        transform: translateY(-2px);
    }

    .admin-command-card {
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 24px;
        padding: 22px;
        backdrop-filter: blur(14px);
        min-height: 100%;
    }

    .command-label {
        color: rgba(255, 255, 255, 0.7);
        font-size: 12px;
        text-transform: uppercase;
        font-weight: 800;
        letter-spacing: 1px;
    }

    .command-value {
        font-size: 46px;
        line-height: 1;
        font-weight: 900;
        margin: 10px 0;
    }

    .command-mini-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        margin-top: 18px;
    }

    .command-mini {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.14);
        border-radius: 16px;
        padding: 12px;
    }

    .command-mini strong {
        display: block;
        font-size: 20px;
        line-height: 1;
    }

    .command-mini span {
        display: block;
        margin-top: 7px;
        color: rgba(255, 255, 255, 0.7);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .admin-section-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-top: 18px;
    }

    .ops-card {
        background: #ffffff;
        border: 1px solid var(--admin-border);
        border-radius: 24px;
        padding: 20px;
        box-shadow: 0 14px 35px rgba(70, 0, 0, 0.07);
        position: relative;
        overflow: hidden;
    }

    .ops-card::after {
        content: '';
        position: absolute;
        width: 86px;
        height: 86px;
        border-radius: 999px;
        right: -28px;
        top: -28px;
        background: rgba(128, 0, 0, 0.06);
    }

    .ops-icon {
        width: 44px;
        height: 44px;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--admin-maroon-soft);
        color: var(--admin-maroon);
        font-weight: 900;
        margin-bottom: 14px;
        position: relative;
        z-index: 1;
    }

    .ops-label {
        color: var(--admin-muted);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.9px;
        position: relative;
        z-index: 1;
    }

    .ops-value {
        color: var(--admin-ink);
        font-size: 32px;
        font-weight: 900;
        margin: 4px 0;
        position: relative;
        z-index: 1;
    }

    .ops-note {
        color: var(--admin-muted);
        font-size: 13px;
        position: relative;
        z-index: 1;
    }

    .admin-main-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.2fr) minmax(360px, 0.8fr);
        gap: 18px;
        margin-top: 18px;
    }

    .admin-panel {
        background: #ffffff;
        border: 1px solid var(--admin-border);
        border-radius: 26px;
        padding: 22px;
        box-shadow: 0 14px 35px rgba(70, 0, 0, 0.06);
    }

    .panel-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 18px;
        flex-wrap: wrap;
    }

    .panel-title-admin {
        color: var(--admin-ink);
        font-weight: 900;
        font-size: 18px;
        margin: 0;
    }

    .panel-subtitle-admin {
        color: var(--admin-muted);
        font-size: 13px;
        margin-top: 5px;
    }

    .status-pill,
    .badge-good,
    .badge-warn,
    .badge-danger-soft,
    .badge-dark-soft,
    .badge-maroon-soft,
    .badge-gold-soft,
    .badge-neutral {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        padding: 7px 11px;
        font-size: 12px;
        font-weight: 800;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .badge-good { background: #eefaf2; color: #166534; border-color: #d3f2dc; }
    .badge-warn { background: #fff8e7; color: #8a5b00; border-color: #f4e1aa; }
    .badge-danger-soft { background: #fff0f0; color: #9b1c1c; border-color: #f5d0d0; }
    .badge-dark-soft { background: #f2f3f5; color: #111827; border-color: #e5e7eb; }
    .badge-maroon-soft { background: var(--admin-maroon-soft); color: var(--admin-maroon); border-color: #f3d6d6; }
    .badge-gold-soft { background: #fff7e8; color: #9a6500; border-color: #f2d494; }
    .badge-neutral { background: #f7f7f8; color: #4b5563; border-color: #e5e7eb; }

    .tracer-spotlight {
        border-radius: 24px;
        background: linear-gradient(135deg, #fffafa, #fff3f3);
        border: 1px solid #f0d7d7;
        padding: 20px;
    }

    .tracer-title {
        color: var(--admin-ink);
        font-size: 20px;
        font-weight: 900;
        margin-bottom: 6px;
    }

    .tracer-desc {
        color: var(--admin-muted);
        font-size: 13px;
        line-height: 1.65;
    }

    .progress-admin {
        height: 11px;
        background: #f2e5e5;
        border-radius: 999px;
        overflow: hidden;
        margin: 14px 0 8px;
    }

    .progress-admin .bar {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, var(--admin-maroon), #b90d0d);
    }

    .metric-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        margin-top: 14px;
    }

    .metric-chip {
        border-radius: 16px;
        padding: 13px;
        background: #ffffff;
        border: 1px solid #f2dddd;
    }

    .metric-chip strong {
        display: block;
        color: var(--admin-ink);
        font-size: 22px;
        line-height: 1;
    }

    .metric-chip span {
        display: block;
        margin-top: 7px;
        font-size: 11px;
        color: var(--admin-muted);
        font-weight: 800;
        text-transform: uppercase;
    }

    .activity-list {
        display: grid;
        gap: 12px;
    }

    .activity-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        padding: 14px;
        border-radius: 18px;
        border: 1px solid #f0e4e4;
        background: #ffffff;
        text-decoration: none;
        color: inherit;
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .activity-item:hover {
        transform: translateY(-2px);
        border-color: #e3bdbd;
        box-shadow: 0 14px 24px rgba(128, 0, 0, 0.08);
    }

    .activity-main strong {
        display: block;
        color: var(--admin-ink);
        font-size: 14px;
    }

    .activity-main span {
        display: block;
        color: var(--admin-muted);
        font-size: 12px;
        margin-top: 4px;
    }

    .shortcut-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .shortcut-tile {
        text-decoration: none;
        color: inherit;
        border-radius: 20px;
        padding: 16px;
        background: #fbf8f8;
        border: 1px solid #f0e4e4;
        min-height: 118px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .shortcut-tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 16px 28px rgba(128, 0, 0, 0.08);
    }

    .shortcut-tile strong {
        color: var(--admin-ink);
        font-size: 14px;
    }

    .shortcut-tile span {
        color: var(--admin-muted);
        font-size: 12px;
        line-height: 1.5;
    }

    .shortcut-arrow {
        align-self: flex-end;
        color: var(--admin-maroon);
        font-weight: 900;
    }

    .chart-wrap {
        height: 320px;
    }

    .admin-table-list {
        overflow-x: auto;
    }

    .admin-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 10px;
    }

    .admin-table th {
        color: var(--admin-muted);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        padding: 0 12px;
    }

    .admin-table td {
        background: #fbf8f8;
        border-top: 1px solid #f0e4e4;
        border-bottom: 1px solid #f0e4e4;
        padding: 13px 12px;
        vertical-align: middle;
        color: var(--admin-ink);
    }

    .admin-table td:first-child {
        border-left: 1px solid #f0e4e4;
        border-radius: 16px 0 0 16px;
    }

    .admin-table td:last-child {
        border-right: 1px solid #f0e4e4;
        border-radius: 0 16px 16px 0;
    }

    .empty-admin {
        border: 1px dashed #e4c6c6;
        background: #fffafa;
        border-radius: 20px;
        padding: 22px;
        color: var(--admin-muted);
        text-align: center;
    }

    @media (max-width: 1200px) {
        .admin-section-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .admin-main-grid,
        .admin-hero-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 680px) {
        .admin-command-hero,
        .admin-panel {
            border-radius: 22px;
            padding: 18px;
        }

        .admin-section-grid,
        .shortcut-grid,
        .metric-row,
        .command-mini-grid {
            grid-template-columns: 1fr;
        }

        .admin-hero-actions {
            flex-direction: column;
        }

        .admin-btn-white,
        .admin-btn-primary,
        .admin-btn-ghost {
            width: 100%;
        }
    }
</style>

<div class="admin-shell">
    <section class="admin-command-hero">
        <div class="admin-hero-grid">
            <div>
                <span class="admin-kicker">◆ Admin Operations</span>
                <h2 class="admin-hero-title">Command center for GradLink operations</h2>
                <p class="admin-hero-text">
                    Monitor the whole platform from one place: alumni participation, partner hiring activity,
                    tracer study progress, job posts, applications, surveys, and announcements.
                </p>

                <div class="admin-hero-actions">
                    <a href="tracer_dashboard.php" class="admin-btn-white">Open Tracer Dashboard →</a>
                    <a href="surveys.php" class="admin-btn-ghost">Survey Control Center</a>
                    <a href="announcements.php" class="admin-btn-ghost">Manage Announcements</a>
                </div>
            </div>

            <div class="admin-command-card">
                <div class="command-label">Platform Users</div>
                <div class="command-value"><?= number_format($totalUsers) ?></div>
                <div style="color: rgba(255,255,255,0.75); font-size: 13px;">
                    <?= number_format($userStatusCounts['active']) ?> active accounts across alumni, partners, and admins.
                </div>

                <div class="command-mini-grid">
                    <div class="command-mini">
                        <strong><?= number_format($userCounts['alumni']) ?></strong>
                        <span>Alumni</span>
                    </div>
                    <div class="command-mini">
                        <strong><?= number_format($userCounts['partner']) ?></strong>
                        <span>Partners</span>
                    </div>
                    <div class="command-mini">
                        <strong><?= number_format($userCounts['admin']) ?></strong>
                        <span>Admins</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-section-grid">
        <div class="ops-card">
            <div class="ops-label">Alumni Accounts</div>
            <div class="ops-value"><?= number_format($userCounts['alumni']) ?></div>
            <div class="ops-note"><?= number_format($userStatusCounts['active']) ?> total active platform users</div>
        </div>

        <div class="ops-card">
            <div class="ops-label">Open Jobs</div>
            <div class="ops-value"><?= number_format($jobCounts['Open']) ?></div>
            <div class="ops-note"><?= number_format($totalJobPosts) ?> total job posts</div>
        </div>

        <div class="ops-card">
            <div class="ops-label">Applications</div>
            <div class="ops-value"><?= number_format($totalApplications) ?></div>
            <div class="ops-note"><?= number_format($pendingApplications) ?> pending or under review</div>
        </div>

        <div class="ops-card">
            <div class="ops-label">Tracer Participation</div>
            <div class="ops-value"><?= number_format($tracerParticipationRate) ?>%</div>
            <div class="ops-note"><?= number_format($tracerParticipants) ?> alumni participated</div>
        </div>
    </section>

    <section class="admin-main-grid">
        <div class="admin-panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title-admin">Tracer Study Pulse</h3>
                    <div class="panel-subtitle-admin">Current active tracer study and latest alumni career status activity.</div>
                </div>
                <?php if ($activeTracerSurvey): ?>
                    <span class="badge-good">Active</span>
                <?php else: ?>
                    <span class="badge-danger-soft">No Active Tracer</span>
                <?php endif; ?>
            </div>

            <?php if ($activeTracerSurvey): ?>
                <div class="tracer-spotlight">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div>
                            <div class="tracer-title"><?= htmlspecialchars($activeTracerSurvey['title']) ?></div>
                            <div class="tracer-desc">
                                <?= htmlspecialchars($activeTracerSurvey['description'] ?: 'This active tracer survey is used for alumni career tracking and career update submissions.') ?>
                            </div>
                        </div>
                        <a href="tracer_dashboard.php" class="admin-btn-primary">View Details</a>
                    </div>

                    <div class="progress-admin">
                        <div class="bar" style="width: <?= min(100, max(0, $tracerParticipationRate)) ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between text-muted small flex-wrap gap-2">
                        <span><?= number_format($tracerParticipants) ?> of <?= number_format($userCounts['alumni']) ?> alumni participated</span>
                        <span><?= number_format($tracerParticipationRate) ?>% participation rate</span>
                    </div>

                    <div class="metric-row">
                        <div class="metric-chip">
                            <strong><?= number_format($totalCareerUpdates) ?></strong>
                            <span>Career Updates</span>
                        </div>
                        <div class="metric-chip">
                            <strong><?= number_format($employmentRate) ?>%</strong>
                            <span>Employment Rate</span>
                        </div>
                        <div class="metric-chip">
                            <strong><?= formatShortDate($latestCareerUpdate) ?></strong>
                            <span>Latest Update</span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-admin">
                    No active tracer survey yet. Create or activate a tracer study from the Survey Control Center.
                    <div class="mt-3">
                        <a href="surveys.php" class="admin-btn-primary">Go to Surveys</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="admin-panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title-admin">Management Shortcuts</h3>
                    <div class="panel-subtitle-admin">Fast access to the main admin modules.</div>
                </div>
            </div>

            <div class="shortcut-grid">
                <a href="alumni_accounts.php" class="shortcut-tile">
                    <div>
                        <strong>Alumni Accounts</strong>
                        <span>Review profiles, account status, and alumni records.</span>
                    </div>
                    <div class="shortcut-arrow">→</div>
                </a>

                <a href="partner_accounts.php" class="shortcut-tile">
                    <div>
                        <strong>Industry Partners</strong>
                        <span>Manage company and recruiter accounts.</span>
                    </div>
                    <div class="shortcut-arrow">→</div>
                </a>

                <a href="applications.php" class="shortcut-tile">
                    <div>
                        <strong>Applications</strong>
                        <span>Monitor hiring status and application progress.</span>
                    </div>
                    <div class="shortcut-arrow">→</div>
                </a>

                <a href="surveys.php" class="shortcut-tile">
                    <div>
                        <strong>Survey Center</strong>
                        <span>Manage general surveys and tracer study forms.</span>
                    </div>
                    <div class="shortcut-arrow">→</div>
                </a>
            </div>
        </div>
    </section>

    <section class="admin-main-grid">
        <div class="admin-panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title-admin">Platform Analytics</h3>
                    <div class="panel-subtitle-admin">Users, jobs, and applications at a glance.</div>
                </div>
            </div>
            <div class="chart-wrap">
                <canvas id="platformChart"></canvas>
            </div>
        </div>

        <div class="admin-panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title-admin">Application Flow</h3>
                    <div class="panel-subtitle-admin">Current recruitment pipeline summary.</div>
                </div>
                <span class="badge-maroon-soft"><?= number_format($totalApplications) ?> total</span>
            </div>
            <div class="chart-wrap">
                <canvas id="applicationChart"></canvas>
            </div>
        </div>
    </section>

    <section class="admin-section-grid">
        <div class="ops-card">
            <div class="ops-label">Alumni With Work</div>
            <div class="ops-value"><?= number_format($workAlignment['working']) ?></div>
            <div class="ops-note">of <?= number_format($workAlignment['total_alumni']) ?> alumni accounts</div>
        </div>

        <div class="ops-card">
            <div class="ops-label">Work Matches Course</div>
            <div class="ops-value"><?= number_format($workAlignment['match']) ?></div>
            <div class="ops-note"><?= number_format($alignRate) ?>% of alumni with work</div>
        </div>

        <div class="ops-card">
            <div class="ops-label">Work Does Not Match Course</div>
            <div class="ops-value"><?= number_format($workAlignment['no_match']) ?></div>
            <div class="ops-note">Working in a different field</div>
        </div>

        <div class="ops-card">
            <div class="ops-label">Job Role Not Provided</div>
            <div class="ops-value"><?= number_format($workAlignment['unknown']) ?></div>
            <div class="ops-note">Working, but no job role recorded yet</div>
        </div>
    </section>

    <section class="admin-main-grid">
        <div class="admin-panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title-admin">Work vs. Course Alignment</h3>
                    <div class="panel-subtitle-admin">Alumni with work: job related to their course or not.</div>
                </div>
                <span class="badge-maroon-soft"><?= number_format($workAlignment['working']) ?> with work</span>
            </div>
            <?php if ($workAlignment['working'] === 0): ?>
                <div class="empty-admin">No working alumni found yet.</div>
            <?php else: ?>
                <div class="chart-wrap">
                    <canvas id="alignmentChart"></canvas>
                </div>
            <?php endif; ?>
        </div>

        <div class="admin-panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title-admin">Alignment by Course</h3>
                    <div class="panel-subtitle-admin">Based on tracer job role / employer or profile work experience.</div>
                </div>
            </div>
            <?php if (empty($workAlignment['by_course'])): ?>
                <div class="empty-admin">No data yet.</div>
            <?php else: ?>
                <div class="admin-table-list">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>With Work</th>
                                <th>Match</th>
                                <th>No Match</th>
                                <th>No Role</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workAlignment['by_course'] as $courseName => $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($courseName) ?></td>
                                    <td><?= number_format($c['working']) ?></td>
                                    <td><?= number_format($c['match']) ?></td>
                                    <td><?= number_format($c['no_match']) ?></td>
                                    <td><?= number_format($c['unknown']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="admin-main-grid">
        <div class="admin-panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title-admin">Recent Tracer Updates</h3>
                    <div class="panel-subtitle-admin">Latest career submissions from alumni.</div>
                </div>
                <a href="tracer_dashboard.php" class="badge-maroon-soft text-decoration-none">Open tracer dashboard</a>
            </div>

            <?php if (empty($latestTracerUpdates)): ?>
                <div class="empty-admin">No career updates yet.</div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($latestTracerUpdates as $item): ?>
                        <a href="view_tracer_response.php?id=<?= (int) $item['id'] ?>" class="activity-item">
                            <div class="activity-main">
                                <strong><?= htmlspecialchars(trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''))) ?></strong>
                                <span>
                                    <?= htmlspecialchars($item['course'] ?? 'Course not set') ?>
                                    <?= !empty($item['graduation_year']) ? ' • Batch ' . htmlspecialchars($item['graduation_year']) : '' ?>
                                </span>
                            </div>
                            <span class="badge-neutral"><?= formatShortDate($item['submitted_at']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="admin-panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title-admin">Recent Applications</h3>
                    <div class="panel-subtitle-admin">Newest hiring activity from alumni.</div>
                </div>
                <a href="applications.php" class="badge-maroon-soft text-decoration-none">View all</a>
            </div>

            <?php if (empty($recentApplications)): ?>
                <div class="empty-admin">No applications submitted yet.</div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($recentApplications as $app): ?>
                        <a href="applications.php" class="activity-item">
                            <div class="activity-main">
                                <strong><?= htmlspecialchars($app['applicant_name'] ?: 'Applicant') ?></strong>
                                <span>
                                    <?= htmlspecialchars($app['job_title'] ?? 'Job post') ?>
                                    <?= !empty($app['company_name']) ? ' • ' . htmlspecialchars($app['company_name']) : '' ?>
                                </span>
                            </div>
                            <span class="<?= adminBadgeClass($app['status'] ?? '') ?>"><?= htmlspecialchars($app['status'] ?? 'Pending') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="admin-main-grid">
        <div class="admin-panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title-admin">Recent Users</h3>
                    <div class="panel-subtitle-admin">Latest registered accounts.</div>
                </div>
            </div>

            <?php if (empty($recentUsers)): ?>
                <div class="empty-admin">No users found.</div>
            <?php else: ?>
                <div class="admin-table-list">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentUsers as $user): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></strong>
                                        <div class="text-muted small"><?= htmlspecialchars($user['email'] ?? '') ?></div>
                                    </td>
                                    <td><span class="<?= adminBadgeClass($user['role'] ?? '') ?>"><?= htmlspecialchars(ucfirst($user['role'] ?? 'user')) ?></span></td>
                                    <td><span class="<?= adminBadgeClass($user['status'] ?? '') ?>"><?= htmlspecialchars(ucfirst($user['status'] ?? 'active')) ?></span></td>
                                    <td><?= formatShortDate($user['created_at'] ?? null) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="admin-panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title-admin">Recent Job Posts</h3>
                    <div class="panel-subtitle-admin">Latest openings posted by partners.</div>
                </div>
                <a href="job_posts.php" class="badge-maroon-soft text-decoration-none">Manage jobs</a>
            </div>

            <?php if (empty($recentJobs)): ?>
                <div class="empty-admin">No job posts available yet.</div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($recentJobs as $job): ?>
                        <a href="job_posts.php" class="activity-item">
                            <div class="activity-main">
                                <strong><?= htmlspecialchars($job['title'] ?? 'Untitled job') ?></strong>
                                <span>
                                    <?= htmlspecialchars($job['company_name'] ?? 'Company not set') ?>
                                    <?= !empty($job['deadline']) ? ' • Deadline ' . formatShortDate($job['deadline']) : '' ?>
                                </span>
                            </div>
                            <span class="<?= adminBadgeClass($job['status'] ?? '') ?>"><?= htmlspecialchars($job['status'] ?? 'Open') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="admin-section-grid">
        <div class="ops-card">
       
            <div class="ops-label">Surveys</div>
            <div class="ops-value"><?= number_format((int) ($surveyStats['total_surveys'] ?? 0)) ?></div>
            <div class="ops-note"><?= number_format((int) ($surveyStats['active_surveys'] ?? 0)) ?> active • <?= number_format((int) ($surveyStats['tracer_surveys'] ?? 0)) ?> tracer</div>
        </div>

        <div class="ops-card">
          
            <div class="ops-label">Announcements</div>
            <div class="ops-value"><?= number_format((int) ($announcementStats['total'] ?? 0)) ?></div>
            <div class="ops-note"><?= number_format((int) ($announcementStats['published'] ?? 0)) ?> published</div>
        </div>

        <div class="ops-card">
        
            <div class="ops-label">Partners</div>
            <div class="ops-value"><?= number_format($userCounts['partner']) ?></div>
            <div class="ops-note">Industry partner accounts</div>
        </div>

        <div class="ops-card">
        
            <div class="ops-label">Successful Applications</div>
            <div class="ops-value"><?= number_format($successfulApplications) ?></div>
            <div class="ops-note">Hired or offered applicants</div>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const chartFont = {
        family: "Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
    };

    Chart.defaults.font.family = chartFont.family;
    Chart.defaults.color = '#6b7280';

    const deepMaroon = '#800000';
    const darkMaroon = '#4d0000';
    const gold = '#c8942e';
    const softMaroon = 'rgba(128, 0, 0, 0.25)';
    const softGold = 'rgba(200, 148, 46, 0.45)';
    const gridColor = 'rgba(128, 0, 0, 0.08)';

    const platformChart = document.getElementById('platformChart');
    const applicationChart = document.getElementById('applicationChart');

    if (platformChart) {
        new Chart(platformChart, {
            type: 'bar',
            data: {
                labels: ['Alumni', 'Partners', 'Open Jobs', 'Applications', 'Tracer Updates'],
                datasets: [{
                    label: 'Platform Count',
                    data: [
                        <?= (int) $userCounts['alumni'] ?>,
                        <?= (int) $userCounts['partner'] ?>,
                        <?= (int) $jobCounts['Open'] ?>,
                        <?= (int) $totalApplications ?>,
                        <?= (int) $totalCareerUpdates ?>
                    ],
                    backgroundColor: [deepMaroon, darkMaroon, gold, softMaroon, softGold],
                    borderRadius: 14,
                    barThickness: 38
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#4b5563' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor },
                        border: { display: false },
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }

    const alignmentChart = document.getElementById('alignmentChart');
    if (alignmentChart) {
        new Chart(alignmentChart, {
            type: 'doughnut',
            data: {
                labels: ['Work matches course', 'Work does not match course', 'Job role not provided'],
                datasets: [{
                    data: [
                        <?= (int) $workAlignment['match'] ?>,
                        <?= (int) $workAlignment['no_match'] ?>,
                        <?= (int) $workAlignment['unknown'] ?>
                    ],
                    backgroundColor: ['#246b43', '#b42318', '#c9ced6'],
                    borderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, boxWidth: 8, padding: 14 }
                    }
                }
            }
        });
    }

    if (applicationChart) {
        new Chart(applicationChart, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Under Review', 'Interview', 'Offered', 'Hired', 'Not Selected'],
                datasets: [{
                    data: [
                        <?= (int) ($appCounts['Pending'] ?? 0) ?>,
                        <?= (int) ($appCounts['Under Review'] ?? 0) ?>,
                        <?= (int) ($appCounts['Interview Scheduled'] ?? 0) ?>,
                        <?= (int) ($appCounts['Offered'] ?? 0) ?>,
                        <?= (int) ($appCounts['Hired'] ?? 0) ?>,
                        <?= (int) (($appCounts['Not Selected'] ?? 0) + ($appCounts['Rejected'] ?? 0)) ?>
                    ],
                    backgroundColor: [deepMaroon, darkMaroon, gold, '#d6a85f', '#246b43', '#b42318'],
                    borderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                            padding: 14
                        }
                    }
                }
            }
        });
    }
</script>

<?php require_once '../includes/dashboard_footer.php'; ?>
