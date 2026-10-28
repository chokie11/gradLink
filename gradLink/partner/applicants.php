<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';
require_once '../includes/profile_helpers.php';

requireRole(['partner']);

$user_id = $_SESSION['user_id'];

$allowedStatuses = [
    'Pending',
    'Under Review',
    'Shortlisted',
    'For Interview',
    'Interview Scheduled',
    'Offered',
    'Hired',
    'Rejected',
    'Withdrawn'
];

/*
    Legacy statuses are included so old records still appear correctly
    if you have not yet migrated Reviewed -> Under Review and Not Selected -> Rejected.
*/
$legacyStatuses = ['Reviewed', 'Not Selected'];
$filterStatuses = array_merge($allowedStatuses, $legacyStatuses);

$filterStatus = trim($_GET['status'] ?? '');
$filterJobId = trim($_GET['job_id'] ?? '');
$search = trim($_GET['search'] ?? '');

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

function statusBadgeClass($status)
{
    return match ($status) {
        'Pending' => 'status-pending',
        'Under Review', 'Reviewed' => 'status-review',
        'Shortlisted' => 'status-shortlisted',
        'For Interview', 'Interview Scheduled' => 'status-interview',
        'Offered' => 'status-offered',
        'Hired' => 'status-hired',
        'Rejected', 'Not Selected' => 'status-rejected',
        'Withdrawn' => 'status-withdrawn',
        default => 'status-default'
    };
}

function employmentBadgeClass($status)
{
    return match ($status) {
        'Employed' => 'employment-employed',
        'Unemployed' => 'employment-unemployed',
        'Self-Employed' => 'employment-self',
        'Student' => 'employment-student',
        default => 'employment-default'
    };
}

function formatDisplayDateTime($datetime)
{
    if (empty($datetime)) {
        return '—';
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return '—';
    }

    return date('F j, Y g:i A', $timestamp);
}

function formatShortDate($datetime)
{
    if (empty($datetime)) {
        return '—';
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return '—';
    }

    return date('M j, Y', $timestamp);
}

function safeValue($value, $fallback = '—')
{
    $value = trim((string) ($value ?? ''));

    return $value !== '' ? $value : $fallback;
}

function initialsFromName($name)
{
    $name = trim((string) $name);

    if ($name === '') {
        return 'AP';
    }

    $parts = preg_split('/\s+/', $name);
    $initials = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'AP';
}

function skillTags($skills, $limit = 8)
{
    $skills = trim((string) ($skills ?? ''));

    if ($skills === '') {
        return [];
    }

    $parts = preg_split('/[,\n\r;|]+/', $skills);
    $clean = [];

    foreach ($parts as $skill) {
        $skill = trim($skill);

        if ($skill !== '') {
            $clean[] = $skill;
        }

        if (count($clean) >= $limit) {
            break;
        }
    }

    return $clean;
}

function buildFilterUrl($overrides = [])
{
    $query = [
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? '',
        'job_id' => $_GET['job_id'] ?? ''
    ];

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    $query = array_filter($query, function ($value) {
        return $value !== '' && $value !== null;
    });

    return 'applicants.php' . (!empty($query) ? '?' . http_build_query($query) : '');
}

/*
    Get all job posts of this partner for the job filter dropdown.
*/
$jobStmt = $pdo->prepare("
    SELECT id, title
    FROM job_posts
    WHERE partner_id = ?
    ORDER BY title ASC
");
$jobStmt->execute([$user_id]);
$jobPosts = $jobStmt->fetchAll(PDO::FETCH_ASSOC);

/*
    Overall status counts for dashboard cards.
*/
$countStmt = $pdo->prepare("
    SELECT a.status, COUNT(*) AS total
    FROM applications a
    INNER JOIN job_posts jp ON a.job_post_id = jp.id
    WHERE jp.partner_id = ?
    GROUP BY a.status
");
$countStmt->execute([$user_id]);

$statusCounts = [];
$totalApplicants = 0;

foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
    $totalApplicants += (int) $row['total'];
}

$pendingCount = $statusCounts['Pending'] ?? 0;
$underReviewCount = ($statusCounts['Under Review'] ?? 0) + ($statusCounts['Reviewed'] ?? 0);
$shortlistedCount = $statusCounts['Shortlisted'] ?? 0;
$interviewCount = ($statusCounts['For Interview'] ?? 0) + ($statusCounts['Interview Scheduled'] ?? 0);
$offeredCount = $statusCounts['Offered'] ?? 0;
$hiredCount = $statusCounts['Hired'] ?? 0;
$rejectedCount = ($statusCounts['Rejected'] ?? 0) + ($statusCounts['Not Selected'] ?? 0);
$withdrawnCount = $statusCounts['Withdrawn'] ?? 0;
$activePipelineCount = $pendingCount + $underReviewCount + $shortlistedCount + $interviewCount + $offeredCount;

/*
    Main applicants query with optional filters.
*/
$sql = "
    SELECT 
        a.id AS application_id,
        a.status,
        a.applied_at,
        a.resume_id,
        a.applicant_name,
        ap.profile_photo AS alumni_photo,
        a.applicant_email,
        a.applicant_phone,
        a.applicant_address,
        a.applicant_course,
        a.applicant_major,
        a.applicant_graduation_year,
        a.applicant_employment_status,
        a.applicant_skills,
        a.applicant_summary,
        a.partner_notes,
        a.interview_datetime,
        a.rejection_reason,
        a.status_updated_at,
        jp.id AS job_post_id,
        jp.title AS job_title,
        r.file_name AS resume_file_name,
        r.file_path AS resume_file_path
    FROM applications a
    INNER JOIN job_posts jp ON a.job_post_id = jp.id
    LEFT JOIN resumes r ON a.resume_id = r.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = a.alumni_id
    WHERE jp.partner_id = ?
";

$params = [$user_id];

if ($filterStatus !== '' && in_array($filterStatus, $filterStatuses, true)) {
    $sql .= " AND a.status = ?";
    $params[] = $filterStatus;
}

if ($filterJobId !== '' && ctype_digit((string) $filterJobId)) {
    $sql .= " AND jp.id = ?";
    $params[] = $filterJobId;
}

if ($search !== '') {
    $sql .= "
        AND (
            a.applicant_name LIKE ?
            OR a.applicant_email LIKE ?
            OR a.applicant_phone LIKE ?
            OR a.applicant_course LIKE ?
            OR a.applicant_major LIKE ?
            OR a.applicant_skills LIKE ?
            OR jp.title LIKE ?
        )
    ";

    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY a.applied_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filteredApplicantsCount = count($applicants);

$pageTitle = 'Applicants';
$pageSubtitle = 'Review submitted applications and manage your recruitment pipeline.';
$activePage = 'applicants';

require_once '../includes/dashboard_header.php';
?>

<style>
    .partner-applicants-page {
        --partner-gold: #d08c0c;
        --partner-dark: #9f6505;
        --partner-soft: #fff3d8;
        --partner-soft-2: #fff8e8;
        --partner-border: #f0d6a0;
        --partner-ink: #2f2106;
        --partner-muted: #7d6c54;
    }

    .partner-applicants-page .recruitment-hero {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(240, 214, 160, 0.95);
        border-radius: 30px;
        padding: 34px;
        background:
            radial-gradient(circle at 88% 8%, rgba(208, 140, 12, 0.22), transparent 34%),
            linear-gradient(135deg, #ffffff 0%, #fff8e8 55%, #fff3d8 100%);
        box-shadow: 0 20px 50px rgba(126, 80, 0, 0.10);
        margin-bottom: 24px;
    }

    .partner-applicants-page .recruitment-hero::before {
        content: "";
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(208, 140, 12, 0.07) 1px, transparent 1px),
            linear-gradient(90deg, rgba(208, 140, 12, 0.07) 1px, transparent 1px);
        background-size: 42px 42px;
        mask-image: linear-gradient(90deg, black, transparent 82%);
        pointer-events: none;
    }

    .partner-applicants-page .recruitment-hero-content {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1.5fr) minmax(260px, 0.5fr);
        gap: 28px;
        align-items: stretch;
    }

    .partner-applicants-page .eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 999px;
        background: #fff;
        color: var(--partner-dark);
        border: 1px solid var(--partner-border);
        font-size: 12px;
        font-weight: 900;
        letter-spacing: 1.2px;
        text-transform: uppercase;
    }

    .partner-applicants-page .hero-heading {
        margin: 18px 0 10px;
        max-width: 880px;
        color: var(--partner-ink);
        font-size: clamp(34px, 4vw, 58px);
        line-height: 0.98;
        font-weight: 950;
        letter-spacing: -2.5px;
    }

    .partner-applicants-page .hero-copy {
        max-width: 760px;
        color: var(--partner-muted);
        font-size: 16px;
        line-height: 1.75;
        margin-bottom: 22px;
    }

    .partner-applicants-page .partner-btn-primary,
    .partner-applicants-page .partner-btn-outline,
    .partner-applicants-page .partner-btn-soft {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        border-radius: 16px;
        padding: 12px 18px;
        font-weight: 900;
        text-decoration: none;
        border: 1px solid transparent;
        transition: all 0.2s ease;
        min-height: 46px;
    }

    .partner-applicants-page .partner-btn-primary {
        background: linear-gradient(135deg, #d08c0c, #be7f09);
        color: #fff;
        box-shadow: 0 16px 30px rgba(208, 140, 12, 0.24);
    }

    .partner-applicants-page .partner-btn-primary:hover {
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 18px 34px rgba(208, 140, 12, 0.32);
    }

    .partner-applicants-page .partner-btn-outline {
        background: #fff;
        color: var(--partner-dark);
        border-color: var(--partner-border);
    }

    .partner-applicants-page .partner-btn-outline:hover {
        color: var(--partner-dark);
        background: #fff3d8;
        transform: translateY(-1px);
    }

    .partner-applicants-page .partner-btn-soft {
        background: #fff8e8;
        color: var(--partner-dark);
        border-color: var(--partner-border);
    }

    .partner-applicants-page .hero-metric-card {
        background: rgba(255, 255, 255, 0.82);
        border: 1px solid rgba(240, 214, 160, 0.95);
        border-radius: 24px;
        padding: 24px;
        box-shadow: 0 18px 35px rgba(126, 80, 0, 0.08);
        min-height: 100%;
    }

    .partner-applicants-page .hero-metric-label {
        color: var(--partner-muted);
        font-size: 12px;
        text-transform: uppercase;
        font-weight: 900;
        letter-spacing: 1px;
    }

    .partner-applicants-page .hero-metric-value {
        color: var(--partner-ink);
        font-size: 54px;
        font-weight: 950;
        line-height: 1;
        margin: 10px 0;
    }

    .partner-applicants-page .pipeline-mini {
        display: grid;
        gap: 10px;
        margin-top: 18px;
    }

    .partner-applicants-page .pipeline-mini-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 10px 12px;
        border: 1px solid #f2dfb8;
        border-radius: 14px;
        background: #fffaf0;
        color: var(--partner-muted);
        font-weight: 800;
        font-size: 13px;
    }

    .partner-applicants-page .partner-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .partner-applicants-page .partner-stat-card {
        position: relative;
        overflow: hidden;
        background: #fff;
        border: 1px solid #f0d6a0;
        border-radius: 22px;
        padding: 20px;
        box-shadow: 0 16px 36px rgba(126, 80, 0, 0.07);
    }

    .partner-applicants-page .partner-stat-card::after {
        content: "";
        position: absolute;
        width: 90px;
        height: 90px;
        right: -32px;
        top: -34px;
        background: rgba(208, 140, 12, 0.13);
        border-radius: 50%;
    }

    .partner-applicants-page .partner-stat-label {
        font-size: 12px;
        color: var(--partner-muted);
        text-transform: uppercase;
        letter-spacing: 1px;
        font-weight: 900;
        position: relative;
        z-index: 1;
    }

    .partner-applicants-page .partner-stat-value {
        color: var(--partner-ink);
        font-size: 34px;
        font-weight: 950;
        line-height: 1;
        margin: 12px 0 8px;
        position: relative;
        z-index: 1;
    }

    .partner-applicants-page .partner-stat-note {
        color: var(--partner-muted);
        font-size: 13px;
        position: relative;
        z-index: 1;
    }

    .partner-applicants-page .filter-shell {
        background: #fff;
        border: 1px solid #f0d6a0;
        border-radius: 26px;
        padding: 22px;
        box-shadow: 0 16px 38px rgba(126, 80, 0, 0.08);
        margin-bottom: 24px;
    }

    .partner-applicants-page .section-kicker {
        color: var(--partner-muted);
        font-size: 12px;
        font-weight: 900;
        letter-spacing: 1px;
        text-transform: uppercase;
    }

    .partner-applicants-page .section-heading {
        color: var(--partner-ink);
        font-size: 22px;
        font-weight: 950;
        letter-spacing: -0.6px;
        margin: 2px 0 0;
    }

    .partner-applicants-page .form-control,
    .partner-applicants-page .form-select {
        border-radius: 16px;
        border: 1px solid #ebd3a1;
        min-height: 52px;
        font-weight: 700;
        color: #3b2a0a;
        background-color: #fff;
    }

    .partner-applicants-page .form-control:focus,
    .partner-applicants-page .form-select:focus {
        border-color: var(--partner-gold);
        box-shadow: 0 0 0 0.2rem rgba(208, 140, 12, 0.16);
    }

    .partner-applicants-page .status-chip-row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 18px;
    }

    .partner-applicants-page .status-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 13px;
        border-radius: 999px;
        border: 1px solid #f0d6a0;
        background: #fffaf0;
        color: var(--partner-muted);
        font-size: 13px;
        font-weight: 900;
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .partner-applicants-page .status-chip:hover,
    .partner-applicants-page .status-chip.active {
        background: linear-gradient(135deg, #d08c0c, #be7f09);
        color: #fff;
        border-color: transparent;
        box-shadow: 0 12px 24px rgba(208, 140, 12, 0.20);
    }

    .partner-applicants-page .applicant-board {
        display: grid;
        gap: 18px;
    }

    .partner-applicants-page .applicant-card {
        background: #fff;
        border: 1px solid #f0d6a0;
        border-radius: 28px;
        padding: 24px;
        box-shadow: 0 18px 42px rgba(126, 80, 0, 0.08);
        transition: all 0.2s ease;
    }

    .partner-applicants-page .applicant-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 22px 50px rgba(126, 80, 0, 0.12);
    }

    .partner-applicants-page .applicant-topline {
        display: flex;
        justify-content: space-between;
        gap: 18px;
        flex-wrap: wrap;
        align-items: flex-start;
    }

    .partner-applicants-page .candidate-main {
        display: flex;
        gap: 16px;
        align-items: flex-start;
    }

    .partner-applicants-page .candidate-avatar {
        width: 58px;
        height: 58px;
        border-radius: 20px;
        background: linear-gradient(135deg, #d08c0c, #e59d18);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
        font-weight: 950;
        flex: 0 0 58px;
        box-shadow: 0 14px 26px rgba(208, 140, 12, 0.24);
        overflow: hidden;
    }

    .partner-applicants-page .candidate-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .partner-applicants-page .candidate-name {
        font-size: 23px;
        font-weight: 950;
        color: var(--partner-ink);
        line-height: 1.1;
        margin: 0;
    }

    .partner-applicants-page .candidate-job {
        margin-top: 8px;
        color: var(--partner-muted);
        font-weight: 750;
    }

    .partner-applicants-page .candidate-date {
        margin-top: 6px;
        color: #9b8561;
        font-size: 13px;
        font-weight: 800;
    }

    .partner-applicants-page .status-badge,
    .partner-applicants-page .employment-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 950;
        letter-spacing: 0.2px;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .partner-applicants-page .status-pending { background: #f6f0e5; color: #79664b; border-color: #e6d6bc; }
    .partner-applicants-page .status-review { background: #eaf3ff; color: #1e5c93; border-color: #cfe3fa; }
    .partner-applicants-page .status-shortlisted { background: #eaf8f7; color: #08746f; border-color: #c9ece8; }
    .partner-applicants-page .status-interview { background: #fff3d8; color: #946000; border-color: #f0d6a0; }
    .partner-applicants-page .status-offered { background: #eefaf1; color: #1d7c3d; border-color: #ccefd5; }
    .partner-applicants-page .status-hired { background: #1f8a47; color: #fff; border-color: #1f8a47; }
    .partner-applicants-page .status-rejected { background: #fff0f0; color: #aa2a2a; border-color: #ffd3d3; }
    .partner-applicants-page .status-withdrawn { background: #efefef; color: #444; border-color: #ddd; }
    .partner-applicants-page .status-default { background: #f7f7f7; color: #666; border-color: #e6e6e6; }

    .partner-applicants-page .employment-employed { background: #eefaf1; color: #1d7c3d; border-color: #ccefd5; }
    .partner-applicants-page .employment-unemployed { background: #fff0f0; color: #aa2a2a; border-color: #ffd3d3; }
    .partner-applicants-page .employment-self { background: #fff3d8; color: #946000; border-color: #f0d6a0; }
    .partner-applicants-page .employment-student { background: #eaf3ff; color: #1e5c93; border-color: #cfe3fa; }
    .partner-applicants-page .employment-default { background: #f7f7f7; color: #666; border-color: #e6e6e6; }

    .partner-applicants-page .candidate-detail-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-top: 20px;
    }

    .partner-applicants-page .detail-tile {
        background: #fffaf0;
        border: 1px solid #f3dfb4;
        border-radius: 18px;
        padding: 13px 14px;
        min-height: 78px;
    }

    .partner-applicants-page .detail-label {
        color: #9b8561;
        font-size: 11px;
        font-weight: 950;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        margin-bottom: 5px;
    }

    .partner-applicants-page .detail-value {
        color: var(--partner-ink);
        font-weight: 900;
        line-height: 1.35;
        word-break: break-word;
    }

    .partner-applicants-page .skill-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }

    .partner-applicants-page .skill-tag {
        display: inline-flex;
        align-items: center;
        padding: 7px 10px;
        border-radius: 999px;
        background: #fff3d8;
        color: #8a5700;
        border: 1px solid #f0d6a0;
        font-size: 12px;
        font-weight: 900;
    }

    .partner-applicants-page .insight-box {
        background: linear-gradient(135deg, #fffaf0, #fff3d8);
        border: 1px solid #f0d6a0;
        border-radius: 22px;
        padding: 18px;
        margin-top: 18px;
    }

    .partner-applicants-page .insight-title {
        color: var(--partner-ink);
        font-weight: 950;
        margin-bottom: 8px;
    }

    .partner-applicants-page .insight-body {
        color: var(--partner-muted);
        line-height: 1.65;
        font-size: 14px;
        margin: 0;
    }

    .partner-applicants-page .progress-details {
        display: grid;
        gap: 10px;
        margin-top: 18px;
        padding: 18px;
        border-radius: 22px;
        background: #fbfbfb;
        border: 1px solid #eee;
    }

    .partner-applicants-page .progress-row {
        color: #6d6254;
        line-height: 1.55;
        font-size: 14px;
    }

    .partner-applicants-page .action-row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 18px;
        align-items: center;
    }

    .partner-applicants-page .empty-state {
        text-align: center;
        padding: 54px 24px;
        border: 1px dashed #e3c88f;
        border-radius: 26px;
        background: #fffaf0;
        color: var(--partner-muted);
    }

    .partner-applicants-page .empty-icon {
        width: 64px;
        height: 64px;
        border-radius: 22px;
        background: linear-gradient(135deg, #d08c0c, #e59d18);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        font-weight: 900;
        margin-bottom: 16px;
    }

    @media (max-width: 1200px) {
        .partner-applicants-page .partner-stat-grid,
        .partner-applicants-page .candidate-detail-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 900px) {
        .partner-applicants-page .recruitment-hero-content {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .partner-applicants-page .recruitment-hero {
            padding: 24px;
            border-radius: 24px;
        }

        .partner-applicants-page .partner-stat-grid,
        .partner-applicants-page .candidate-detail-grid {
            grid-template-columns: 1fr;
        }

        .partner-applicants-page .candidate-main {
            flex-direction: column;
        }
    }
</style>

<div class="partner-applicants-page">
    <?php if (!empty($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?> rounded-4">
            <?= htmlspecialchars($_SESSION['flash_message']) ?>
        </div>
        <?php
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        ?>
    <?php endif; ?>

    <section class="recruitment-hero">
        <div class="recruitment-hero-content">
            <div>
                <span class="eyebrow">Applicant Pipeline</span>
                <h1 class="hero-heading">Review candidates and move the best alumni forward.</h1>
                <p class="hero-copy">
                    Track applications, inspect alumni profiles, open resumes, and update hiring progress from one recruitment workspace.
                </p>

                <div class="d-flex gap-2 flex-wrap">
                    <a href="job_posts.php" class="partner-btn-primary">Manage Job Posts</a>
                    <a href="create_job.php" class="partner-btn-outline">Create Job Post</a>
                    <a href="dashboard.php" class="partner-btn-soft">Back to Dashboard</a>
                </div>
            </div>

            <div class="hero-metric-card">
                <div class="hero-metric-label">Total Applicants</div>
                <div class="hero-metric-value"><?= $totalApplicants ?></div>
                <div class="text-muted small">All applications received across your company job posts.</div>

                <div class="pipeline-mini">
                    <div class="pipeline-mini-row">
                        <span>Active Pipeline</span>
                        <strong><?= $activePipelineCount ?></strong>
                    </div>
                    <div class="pipeline-mini-row">
                        <span>Hired</span>
                        <strong><?= $hiredCount ?></strong>
                    </div>
                    <div class="pipeline-mini-row">
                        <span>Pending Review</span>
                        <strong><?= $pendingCount ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="partner-stat-grid">
        <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Pending'])) ?>" class="partner-stat-card text-decoration-none">
            <div class="partner-stat-label">Pending</div>
            <div class="partner-stat-value"><?= $pendingCount ?></div>
            <div class="partner-stat-note">Need first review</div>
        </a>

        <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Under Review'])) ?>" class="partner-stat-card text-decoration-none">
            <div class="partner-stat-label">Under Review</div>
            <div class="partner-stat-value"><?= $underReviewCount ?></div>
            <div class="partner-stat-note">Currently being checked</div>
        </a>

        <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Shortlisted'])) ?>" class="partner-stat-card text-decoration-none">
            <div class="partner-stat-label">Shortlisted</div>
            <div class="partner-stat-value"><?= $shortlistedCount ?></div>
            <div class="partner-stat-note">Potential candidates</div>
        </a>

        <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'For Interview'])) ?>" class="partner-stat-card text-decoration-none">
            <div class="partner-stat-label">Interview Stage</div>
            <div class="partner-stat-value"><?= $interviewCount ?></div>
            <div class="partner-stat-note">For interview or scheduled</div>
        </a>

        <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Offered'])) ?>" class="partner-stat-card text-decoration-none">
            <div class="partner-stat-label">Offered</div>
            <div class="partner-stat-value"><?= $offeredCount ?></div>
            <div class="partner-stat-note">With job offer</div>
        </a>

        <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Hired'])) ?>" class="partner-stat-card text-decoration-none">
            <div class="partner-stat-label">Hired</div>
            <div class="partner-stat-value"><?= $hiredCount ?></div>
            <div class="partner-stat-note">Selected candidates</div>
        </a>

        <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Rejected'])) ?>" class="partner-stat-card text-decoration-none">
            <div class="partner-stat-label">Rejected</div>
            <div class="partner-stat-value"><?= $rejectedCount ?></div>
            <div class="partner-stat-note">Not selected</div>
        </a>

        <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Withdrawn'])) ?>" class="partner-stat-card text-decoration-none">
            <div class="partner-stat-label">Withdrawn</div>
            <div class="partner-stat-value"><?= $withdrawnCount ?></div>
            <div class="partner-stat-note">Withdrawn applications</div>
        </a>
    </section>

    <section class="filter-shell">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
            <div>
                <div class="section-kicker">Candidate Filters</div>
                <h2 class="section-heading">Find the applicant you need</h2>
                <div class="text-muted small mt-1">
                    Showing <?= $filteredApplicantsCount ?> of <?= $totalApplicants ?> applicant<?= $totalApplicants === 1 ? '' : 's' ?>.
                </div>
            </div>

            <?php if ($filterStatus !== '' || $filterJobId !== '' || $search !== ''): ?>
                <a href="applicants.php" class="partner-btn-outline">Clear Filters</a>
            <?php endif; ?>
        </div>

        <form method="GET" action="applicants.php">
            <div class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label class="form-label fw-bold">Search Applicant</label>
                    <input 
                        type="text" 
                        name="search" 
                        class="form-control" 
                        placeholder="Name, email, skills, job title..."
                        value="<?= htmlspecialchars($search) ?>"
                    >
                </div>

                <div class="col-lg-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>>
                                <?= htmlspecialchars($status) ?>
                            </option>
                        <?php endforeach; ?>

                        <?php if (($statusCounts['Reviewed'] ?? 0) > 0 || ($statusCounts['Not Selected'] ?? 0) > 0): ?>
                            <option value="Reviewed" <?= $filterStatus === 'Reviewed' ? 'selected' : '' ?>>
                                Reviewed (Old)
                            </option>
                            <option value="Not Selected" <?= $filterStatus === 'Not Selected' ? 'selected' : '' ?>>
                                Not Selected (Old)
                            </option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-lg-3">
                    <label class="form-label fw-bold">Job Post</label>
                    <select name="job_id" class="form-select">
                        <option value="">All Job Posts</option>
                        <?php foreach ($jobPosts as $job): ?>
                            <option value="<?= (int) $job['id'] ?>" <?= $filterJobId == $job['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($job['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2 d-grid">
                    <button type="submit" class="partner-btn-primary">Apply Filters</button>
                </div>
            </div>
        </form>

        <div class="status-chip-row">
            <a href="<?= htmlspecialchars(buildFilterUrl(['status' => ''])) ?>" class="status-chip <?= $filterStatus === '' ? 'active' : '' ?>">All</a>
            <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Pending'])) ?>" class="status-chip <?= $filterStatus === 'Pending' ? 'active' : '' ?>">Pending</a>
            <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Under Review'])) ?>" class="status-chip <?= $filterStatus === 'Under Review' ? 'active' : '' ?>">Under Review</a>
            <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Shortlisted'])) ?>" class="status-chip <?= $filterStatus === 'Shortlisted' ? 'active' : '' ?>">Shortlisted</a>
            <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'For Interview'])) ?>" class="status-chip <?= $filterStatus === 'For Interview' ? 'active' : '' ?>">Interview</a>
            <a href="<?= htmlspecialchars(buildFilterUrl(['status' => 'Hired'])) ?>" class="status-chip <?= $filterStatus === 'Hired' ? 'active' : '' ?>">Hired</a>
        </div>
    </section>

    <section class="filter-shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
            <div>
                <div class="section-kicker">Application Board</div>
                <h2 class="section-heading">Applicant List</h2>
            </div>
            <a href="job_posts.php" class="partner-btn-outline">Open Job Posts</a>
        </div>

        <?php if (empty($applicants)): ?>
            <div class="empty-state">
                <div class="empty-icon">⌕</div>
                <h3 class="fw-bold mb-2">No applicants found</h3>
                <p class="mb-3">
                    No applications matched your selected filters. Try clearing the filters or opening more job posts.
                </p>
                <a href="applicants.php" class="partner-btn-primary">Reset Filters</a>
            </div>
        <?php else: ?>
            <div class="applicant-board">
                <?php foreach ($applicants as $app): ?>
                    <?php
                    $courseCode = safeValue($app['applicant_course'] ?? '');
                    $displayCourse = $courseLabels[$courseCode] ?? $courseCode;
                    $skills = skillTags($app['applicant_skills'] ?? '');
                    ?>

                    <article class="applicant-card">
                        <div class="applicant-topline">
                            <div class="candidate-main">
                                <?php $alumniPhotoUrl = profilePhotoUrl($app['alumni_photo'] ?? '', '../'); ?>
                                <div class="candidate-avatar">
                                    <?php if ($alumniPhotoUrl): ?>
                                        <img src="<?= htmlspecialchars($alumniPhotoUrl) ?>" alt="<?= htmlspecialchars($app['applicant_name'] ?? 'Applicant') ?>" loading="lazy">
                                    <?php else: ?>
                                        <?= htmlspecialchars(initialsFromName($app['applicant_name'] ?? '')) ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h3 class="candidate-name"><?= htmlspecialchars(safeValue($app['applicant_name'] ?? 'Unknown Applicant')) ?></h3>
                                    <div class="candidate-job">
                                        Applied for <strong><?= htmlspecialchars(safeValue($app['job_title'])) ?></strong>
                                    </div>
                                    <div class="candidate-date">
                                        Submitted <?= htmlspecialchars(formatDisplayDateTime($app['applied_at'] ?? '')) ?>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                                <span class="status-badge <?= statusBadgeClass($app['status'] ?? '') ?>">
                                    <?= htmlspecialchars(safeValue($app['status'])) ?>
                                </span>
                                <span class="employment-badge <?= employmentBadgeClass($app['applicant_employment_status'] ?? '') ?>">
                                    <?= htmlspecialchars(safeValue($app['applicant_employment_status'], 'Not specified')) ?>
                                </span>
                            </div>
                        </div>

                        <div class="candidate-detail-grid">
                            <div class="detail-tile">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?= htmlspecialchars(safeValue($app['applicant_email'])) ?></div>
                            </div>

                            <div class="detail-tile">
                                <div class="detail-label">Phone</div>
                                <div class="detail-value"><?= htmlspecialchars(safeValue($app['applicant_phone'])) ?></div>
                            </div>

                            <div class="detail-tile">
                                <div class="detail-label">Course</div>
                                <div class="detail-value"><?= htmlspecialchars($displayCourse) ?></div>
                            </div>

                            <div class="detail-tile">
                                <div class="detail-label">Graduation Year</div>
                                <div class="detail-value"><?= htmlspecialchars(safeValue($app['applicant_graduation_year'])) ?></div>
                            </div>

                            <div class="detail-tile">
                                <div class="detail-label">Major</div>
                                <div class="detail-value"><?= htmlspecialchars(safeValue($app['applicant_major'])) ?></div>
                            </div>

                            <div class="detail-tile">
                                <div class="detail-label">Address</div>
                                <div class="detail-value"><?= htmlspecialchars(safeValue($app['applicant_address'])) ?></div>
                            </div>

                            <div class="detail-tile">
                                <div class="detail-label">Resume</div>
                                <div class="detail-value"><?= htmlspecialchars(safeValue($app['resume_file_name'], 'No file attached')) ?></div>
                            </div>

                            <div class="detail-tile">
                                <div class="detail-label">Last Status Update</div>
                                <div class="detail-value"><?= htmlspecialchars(formatShortDate($app['status_updated_at'] ?? '')) ?></div>
                            </div>
                        </div>

                        <?php if (!empty($skills)): ?>
                            <div class="insight-box">
                                <div class="insight-title">Candidate Skills</div>
                                <div class="skill-wrap">
                                    <?php foreach ($skills as $skill): ?>
                                        <span class="skill-tag"><?= htmlspecialchars($skill) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php elseif (!empty($app['applicant_skills'])): ?>
                            <div class="insight-box">
                                <div class="insight-title">Candidate Skills</div>
                                <p class="insight-body"><?= nl2br(htmlspecialchars($app['applicant_skills'])) ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($app['applicant_summary'])): ?>
                            <div class="insight-box">
                                <div class="insight-title">Applicant Summary</div>
                                <p class="insight-body"><?= nl2br(htmlspecialchars($app['applicant_summary'])) ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($app['partner_notes']) || !empty($app['interview_datetime']) || !empty($app['rejection_reason']) || !empty($app['status_updated_at'])): ?>
                            <div class="progress-details">
                                <?php if (!empty($app['status_updated_at'])): ?>
                                    <div class="progress-row">
                                        <strong>Last Status Update:</strong>
                                        <?= htmlspecialchars(formatDisplayDateTime($app['status_updated_at'])) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($app['interview_datetime'])): ?>
                                    <div class="progress-row">
                                        <strong>Interview Schedule:</strong>
                                        <?= htmlspecialchars(formatDisplayDateTime($app['interview_datetime'])) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($app['partner_notes'])): ?>
                                    <div class="progress-row">
                                        <strong>Partner Notes:</strong><br>
                                        <?= nl2br(htmlspecialchars($app['partner_notes'])) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($app['rejection_reason'])): ?>
                                    <div class="progress-row">
                                        <strong>Reason for Rejection:</strong><br>
                                        <?= nl2br(htmlspecialchars($app['rejection_reason'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="action-row">
                            <?php if (!empty($app['resume_file_path'])): ?>
                                <a 
                                    href="../<?= htmlspecialchars($app['resume_file_path']) ?>" 
                                    class="partner-btn-outline"
                                    target="_blank"
                                >
                                    View Resume
                                </a>
                            <?php endif; ?>

                            <a 
                                href="update_application_status.php?id=<?= (int) $app['application_id'] ?>" 
                                class="partner-btn-primary"
                            >
                                Update Status / View History
                            </a>

                            <a data-loader-text="Analyzing candidates with AI… finding the top 5 matches for this job." 
                                href="top_matches.php?job_id=<?= (int) $app['job_post_id'] ?>" 
                                class="partner-btn-soft"
                            >
                                View AI Top 5 for This Job
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
