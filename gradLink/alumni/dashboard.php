<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT u.first_name, u.last_name, u.email, ap.*
    FROM users u
    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    $profile = [
        'first_name' => $_SESSION['first_name'] ?? '',
        'last_name' => $_SESSION['last_name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
    ];
}

$requiredFields = [
    $profile['student_id'] ?? '',
    $profile['course'] ?? '',
    $profile['graduation_year'] ?? '',
    $profile['phone'] ?? '',
    $profile['address'] ?? ''
];

$coursesWithMajor = ['BSEd', 'BPEd', 'BTLEd', 'BSBA', 'BSIndTech'];
if (in_array(($profile['course'] ?? ''), $coursesWithMajor, true)) {
    $requiredFields[] = $profile['major'] ?? '';
}

$filled = 0;
foreach ($requiredFields as $field) {
    if (!empty(trim((string) $field))) {
        $filled++;
    }
}

$completion = count($requiredFields) > 0 ? round(($filled / count($requiredFields)) * 100) : 0;
$missingFields = max(count($requiredFields) - $filled, 0);
$profileStatusLabel = $completion >= 100 ? 'Complete' : ($completion >= 70 ? 'Almost ready' : 'Needs update');

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

$displayCourse = $courseLabels[$profile['course'] ?? ''] ?? ($profile['course'] ?? '—');
$displayName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$displayName = $displayName !== '' ? $displayName : ($_SESSION['first_name'] ?? 'Alumni');

/*
|--------------------------------------------------------------------------
| Career / Tracer Dashboard Data
|--------------------------------------------------------------------------
*/

$tracerSurveyStmt = $pdo->prepare("
    SELECT id, title, description
    FROM tracer_surveys
    WHERE survey_type = 'tracer'
      AND is_active = 1
    ORDER BY id DESC
    LIMIT 1
");
$tracerSurveyStmt->execute();
$activeTracerSurvey = $tracerSurveyStmt->fetch(PDO::FETCH_ASSOC);

$hasTakenActiveTracer = false;
$activeTracerResponseCount = 0;
$totalCareerUpdates = 0;
$latestCareerUpdate = null;

if ($activeTracerSurvey) {
    $responseStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tracer_responses
        WHERE alumni_id = ?
          AND survey_id = ?
    ");
    $responseStmt->execute([$user_id, $activeTracerSurvey['id']]);
    $activeTracerResponseCount = (int) $responseStmt->fetchColumn();
    $hasTakenActiveTracer = $activeTracerResponseCount > 0;
}

$totalCareerStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tracer_responses r
    INNER JOIN tracer_surveys s ON s.id = r.survey_id
    WHERE r.alumni_id = ?
      AND s.survey_type = 'tracer'
");
$totalCareerStmt->execute([$user_id]);
$totalCareerUpdates = (int) $totalCareerStmt->fetchColumn();

$latestUpdateStmt = $pdo->prepare("
    SELECT r.submitted_at
    FROM tracer_responses r
    INNER JOIN tracer_surveys s ON s.id = r.survey_id
    WHERE r.alumni_id = ?
      AND s.survey_type = 'tracer'
    ORDER BY r.submitted_at DESC, r.id DESC
    LIMIT 1
");
$latestUpdateStmt->execute([$user_id]);
$latestCareerUpdate = $latestUpdateStmt->fetchColumn();

$careerButtonText = $hasTakenActiveTracer ? 'Update Career Status' : 'Take Tracer Survey';
$careerButtonNote = $hasTakenActiveTracer
    ? 'Add your latest employment, education, or career progress.'
    : 'Complete your first tracer survey for career tracking.';

$tracerStatusLabel = 'Unavailable';
$tracerStatusNote = 'No active tracer survey';
if ($activeTracerSurvey && $hasTakenActiveTracer) {
    $tracerStatusLabel = 'Updated';
    $tracerStatusNote = $latestCareerUpdate ? 'Last update: ' . date('M j, Y', strtotime($latestCareerUpdate)) : 'Career status submitted';
} elseif ($activeTracerSurvey) {
    $tracerStatusLabel = 'Pending';
    $tracerStatusNote = 'Tracer survey available';
}

/*
|--------------------------------------------------------------------------
| Job / Application Dashboard Data
|--------------------------------------------------------------------------
*/

$applicationCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM applications
    WHERE alumni_id = ?
");
$applicationCountStmt->execute([$user_id]);
$applicationCount = (int) $applicationCountStmt->fetchColumn();

$recommendedJobsStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM job_recommendations jr
    INNER JOIN job_posts jp ON jp.id = jr.job_post_id
    WHERE jr.alumni_id = ?
      AND jp.status = 'Open'
");
$recommendedJobsStmt->execute([$user_id]);
$recommendedJobsCount = (int) $recommendedJobsStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Job Invitations
|--------------------------------------------------------------------------
| Partner companies can invite alumni from AI Top 5 Matches.
| Keep this safe in case the job_invitations table is not installed yet.
|--------------------------------------------------------------------------
*/

$jobInviteCount = 0;
$newJobInviteCount = 0;
$latestJobInvite = null;

try {
    $jobInviteStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM job_invitations
        WHERE alumni_id = ?
          AND status IN ('Sent', 'Viewed')
    ");
    $jobInviteStmt->execute([$user_id]);
    $jobInviteCount = (int) $jobInviteStmt->fetchColumn();

    $newJobInviteStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM job_invitations
        WHERE alumni_id = ?
          AND status = 'Sent'
    ");
    $newJobInviteStmt->execute([$user_id]);
    $newJobInviteCount = (int) $newJobInviteStmt->fetchColumn();

    $latestInviteStmt = $pdo->prepare("
        SELECT ji.created_at, ji.match_score, jp.title, pp.company_name
        FROM job_invitations ji
        INNER JOIN job_posts jp ON jp.id = ji.job_post_id
        INNER JOIN partner_profiles pp ON pp.user_id = ji.partner_id
        WHERE ji.alumni_id = ?
          AND ji.status IN ('Sent', 'Viewed')
        ORDER BY ji.created_at DESC, ji.id DESC
        LIMIT 1
    ");
    $latestInviteStmt->execute([$user_id]);
    $latestJobInvite = $latestInviteStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $jobInviteCount = 0;
    $newJobInviteCount = 0;
    $latestJobInvite = null;
}


$latestApplicationStmt = $pdo->prepare("
    SELECT a.status, a.applied_at, jp.title
    FROM applications a
    INNER JOIN job_posts jp ON jp.id = a.job_post_id
    WHERE a.alumni_id = ?
    ORDER BY a.applied_at DESC, a.id DESC
    LIMIT 1
");
$latestApplicationStmt->execute([$user_id]);
$latestApplication = $latestApplicationStmt->fetch(PDO::FETCH_ASSOC);

$announcementStmt = $pdo->prepare("
    SELECT *
    FROM announcements
    WHERE status = 'published'
      AND audience IN ('all', 'alumni')
    ORDER BY
        CASE WHEN event_date IS NULL THEN 1 ELSE 0 END,
        event_date ASC,
        created_at DESC
    LIMIT 3
");
$announcementStmt->execute();
$latestAnnouncements = $announcementStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Alumni Dashboard';
$pageSubtitle = 'Track your profile, career progress, opportunities, and tracer updates.';
$activePage = 'dashboard';

require_once '../includes/dashboard_header.php';
?>

<style>
    .alumni-modern-dashboard {
        --dash-maroon: var(--maroon, #800000);
        --dash-dark-maroon: var(--dark-maroon, #5c0000);
        --dash-soft-maroon: rgba(128, 0, 0, 0.08);
        --dash-border: #eadede;
        --dash-muted: #6b7280;
        --dash-ink: #202124;
        --dash-card-shadow: 0 18px 45px rgba(128, 0, 0, 0.08);
        color: var(--dash-ink);
    }

    .alumni-modern-dashboard .dash-hero {
        position: relative;
        overflow: hidden;
        border-radius: 30px;
        padding: 30px;
        background:
            radial-gradient(circle at top right, rgba(255,255,255,0.28), transparent 34%),
            linear-gradient(135deg, var(--dash-maroon) 0%, var(--dash-dark-maroon) 100%);
        color: #fff;
        box-shadow: 0 24px 60px rgba(92, 0, 0, 0.22);
        margin-bottom: 22px;
    }

    .alumni-modern-dashboard .dash-hero::after {
        content: "";
        position: absolute;
        width: 280px;
        height: 280px;
        right: -90px;
        bottom: -130px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.09);
    }

    .alumni-modern-dashboard .hero-inner {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: 1.45fr 0.95fr;
        gap: 24px;
        align-items: stretch;
    }

    .alumni-modern-dashboard .eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255,255,255,0.14);
        border: 1px solid rgba(255,255,255,0.20);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
    }

    .alumni-modern-dashboard .hero-heading {
        margin: 18px 0 10px;
        font-size: clamp(28px, 4vw, 42px);
        line-height: 1.05;
        font-weight: 800;
        letter-spacing: -0.04em;
    }

    .alumni-modern-dashboard .hero-copy {
        color: rgba(255,255,255,0.84);
        max-width: 720px;
        font-size: 15px;
        line-height: 1.7;
        margin-bottom: 22px;
    }

    .alumni-modern-dashboard .hero-actions-modern {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }

    .alumni-modern-dashboard .btn-hero-primary,
    .alumni-modern-dashboard .btn-hero-light,
    .alumni-modern-dashboard .btn-hero-ghost {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        min-height: 46px;
        padding: 12px 17px;
        border-radius: 15px;
        text-decoration: none;
        font-weight: 750;
        transition: .2s ease;
        border: 1px solid transparent;
    }

    .alumni-modern-dashboard .btn-hero-primary {
        background: #fff;
        color: var(--dash-maroon);
        box-shadow: 0 14px 30px rgba(0,0,0,0.12);
    }

    .alumni-modern-dashboard .btn-hero-light {
        background: rgba(255,255,255,0.14);
        color: #fff;
        border-color: rgba(255,255,255,0.22);
    }

    .alumni-modern-dashboard .btn-hero-ghost {
        background: transparent;
        color: #fff;
        border-color: rgba(255,255,255,0.24);
    }

    .alumni-modern-dashboard .btn-hero-primary:hover,
    .alumni-modern-dashboard .btn-hero-light:hover,
    .alumni-modern-dashboard .btn-hero-ghost:hover {
        transform: translateY(-2px);
    }

    .alumni-modern-dashboard .hero-side-card {
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.18);
        border-radius: 24px;
        padding: 22px;
        backdrop-filter: blur(10px);
    }

    .alumni-modern-dashboard .completion-top {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }

    .alumni-modern-dashboard .completion-label {
        color: rgba(255,255,255,0.78);
        font-size: 13px;
        font-weight: 650;
    }

    .alumni-modern-dashboard .completion-value {
        font-size: 44px;
        font-weight: 850;
        letter-spacing: -0.05em;
        line-height: 1;
    }

    .alumni-modern-dashboard .completion-pill {
        align-self: start;
        padding: 7px 10px;
        border-radius: 999px;
        background: rgba(255,255,255,0.15);
        color: #fff;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .alumni-modern-dashboard .modern-progress {
        height: 11px;
        background: rgba(255,255,255,0.18);
        border-radius: 999px;
        overflow: hidden;
    }

    .alumni-modern-dashboard .modern-progress span {
        display: block;
        height: 100%;
        width: var(--progress-width);
        background: linear-gradient(90deg, #fff, #f8c51c);
        border-radius: inherit;
    }

    .alumni-modern-dashboard .completion-note {
        margin-top: 14px;
        color: rgba(255,255,255,0.78);
        font-size: 13px;
        line-height: 1.5;
    }

    .alumni-modern-dashboard .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 22px;
    }

    .alumni-modern-dashboard .metric-card {
        position: relative;
        overflow: hidden;
        background: #fff;
        border: 1px solid var(--dash-border);
        border-radius: 24px;
        padding: 20px;
        box-shadow: var(--dash-card-shadow);
        min-height: 148px;
    }

    .alumni-modern-dashboard .metric-card::after {
        content: "";
        position: absolute;
        inset: auto -30px -50px auto;
        width: 130px;
        height: 130px;
        border-radius: 999px;
        background: var(--dash-soft-maroon);
    }

    .alumni-modern-dashboard .metric-head {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        margin-bottom: 14px;
    }

    .alumni-modern-dashboard .metric-title {
        color: var(--dash-muted);
        font-size: 13px;
        font-weight: 750;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .alumni-modern-dashboard .metric-icon {
        width: 40px;
        height: 40px;
        display: grid;
        place-items: center;
        border-radius: 14px;
        background: var(--dash-soft-maroon);
        color: var(--dash-maroon);
        font-weight: 850;
        font-size: 12px;
    }

    .alumni-modern-dashboard .metric-value {
        position: relative;
        z-index: 1;
        font-size: 32px;
        line-height: 1;
        font-weight: 850;
        letter-spacing: -0.04em;
        margin-bottom: 10px;
    }

    .alumni-modern-dashboard .metric-note {
        position: relative;
        z-index: 1;
        color: var(--dash-muted);
        font-size: 13px;
        line-height: 1.45;
    }

    .alumni-modern-dashboard .dashboard-grid-modern {
        display: grid;
        grid-template-columns: minmax(0, 1.25fr) minmax(360px, .85fr);
        gap: 20px;
        align-items: start;
    }

    .alumni-modern-dashboard .modern-card {
        background: #fff;
        border: 1px solid var(--dash-border);
        border-radius: 26px;
        box-shadow: var(--dash-card-shadow);
        padding: 22px;
    }

    .alumni-modern-dashboard .card-header-modern {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
        margin-bottom: 18px;
    }

    .alumni-modern-dashboard .card-title-modern {
        margin: 0;
        font-size: 18px;
        font-weight: 850;
        letter-spacing: -0.02em;
    }

    .alumni-modern-dashboard .card-subtitle-modern {
        margin-top: 4px;
        color: var(--dash-muted);
        font-size: 13px;
    }

    .alumni-modern-dashboard .status-badge-modern {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 8px 11px;
        border-radius: 999px;
        background: #fff6f6;
        color: var(--dash-maroon);
        border: 1px solid #f0dede;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .alumni-modern-dashboard .career-feature {
        border-radius: 24px;
        padding: 22px;
        background:
            linear-gradient(135deg, rgba(128,0,0,0.08), rgba(128,0,0,0.02)),
            #fff;
        border: 1px solid #eed8d8;
        margin-bottom: 20px;
    }

    .alumni-modern-dashboard .career-feature-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }

    .alumni-modern-dashboard .career-feature h3 {
        margin: 0 0 6px;
        font-size: 21px;
        font-weight: 850;
        letter-spacing: -0.03em;
    }

    .alumni-modern-dashboard .career-feature p {
        margin: 0;
        color: var(--dash-muted);
        font-size: 14px;
        line-height: 1.55;
    }

    .alumni-modern-dashboard .feature-mark {
        width: 48px;
        height: 48px;
        min-width: 48px;
        display: grid;
        place-items: center;
        border-radius: 16px;
        background: var(--dash-maroon);
        color: #fff;
        font-size: 13px;
        font-weight: 900;
    }

    .alumni-modern-dashboard .career-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .alumni-modern-dashboard .btn-modern-maroon,
    .alumni-modern-dashboard .btn-modern-outline,
    .alumni-modern-dashboard .btn-modern-disabled {
        display: inline-flex;
        min-height: 44px;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 11px 15px;
        border-radius: 14px;
        text-decoration: none;
        font-weight: 800;
        transition: .2s ease;
    }

    .alumni-modern-dashboard .btn-modern-maroon {
        color: #fff;
        background: var(--dash-maroon);
        border: 1px solid var(--dash-maroon);
    }

    .alumni-modern-dashboard .btn-modern-outline {
        color: var(--dash-maroon);
        background: #fff;
        border: 1px solid #e6caca;
    }

    .alumni-modern-dashboard .btn-modern-disabled {
        color: #777;
        background: #f4f4f4;
        border: 1px solid #e7e7e7;
        cursor: not-allowed;
    }

    .alumni-modern-dashboard .btn-modern-maroon:hover,
    .alumni-modern-dashboard .btn-modern-outline:hover {
        transform: translateY(-2px);
        color: #fff;
        background: var(--dash-dark-maroon);
        border-color: var(--dash-dark-maroon);
    }

    .alumni-modern-dashboard .profile-list-modern {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .alumni-modern-dashboard .profile-item-modern {
        padding: 16px;
        border: 1px solid #f0e0e0;
        border-radius: 18px;
        background: #fffdfd;
    }

    .alumni-modern-dashboard .profile-key-modern {
        color: var(--dash-muted);
        font-size: 12px;
        font-weight: 750;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 6px;
    }

    .alumni-modern-dashboard .profile-value-modern {
        font-weight: 750;
        line-height: 1.35;
        word-break: break-word;
    }

    .alumni-modern-dashboard .right-stack {
        display: grid;
        gap: 18px;
    }

    .alumni-modern-dashboard .action-list-modern {
        display: grid;
        gap: 11px;
    }

    .alumni-modern-dashboard .action-item-modern {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 15px;
        border-radius: 18px;
        background: #fffafa;
        border: 1px solid #f1dfdf;
        color: var(--dash-ink);
        text-decoration: none;
        transition: .2s ease;
    }

    .alumni-modern-dashboard .action-item-modern:hover {
        transform: translateY(-2px);
        border-color: #dcb9b9;
        color: var(--dash-ink);
        background: #fff6f6;
    }

    .alumni-modern-dashboard .action-left-modern {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .alumni-modern-dashboard .action-icon-modern {
        width: 40px;
        height: 40px;
        min-width: 40px;
        display: grid;
        place-items: center;
        border-radius: 14px;
        background: var(--dash-soft-maroon);
        color: var(--dash-maroon);
        font-weight: 900;
        font-size: 12px;
    }

    .alumni-modern-dashboard .action-title-modern {
        display: block;
        font-weight: 820;
        margin-bottom: 2px;
    }

    .alumni-modern-dashboard .action-desc-modern {
        color: var(--dash-muted);
        font-size: 13px;
        line-height: 1.35;
    }

    .alumni-modern-dashboard .action-arrow-modern {
        color: var(--dash-maroon);
        font-weight: 900;
    }

    .alumni-modern-dashboard .announcement-list-modern {
        display: grid;
        gap: 12px;
    }

    .alumni-modern-dashboard .announcement-item-modern {
        display: block;
        padding: 15px;
        border-radius: 18px;
        background: #fffafa;
        border: 1px solid #f1dfdf;
        color: var(--dash-ink);
        text-decoration: none;
        transition: .2s ease;
    }

    .alumni-modern-dashboard .announcement-item-modern:hover {
        transform: translateY(-2px);
        border-color: #dcb9b9;
        color: var(--dash-ink);
    }

    .alumni-modern-dashboard .announcement-title-modern {
        display: block;
        font-weight: 820;
        margin-bottom: 6px;
    }

    .alumni-modern-dashboard .announcement-meta-modern {
        color: var(--dash-muted);
        font-size: 13px;
    }

    .alumni-modern-dashboard .empty-state-modern {
        border: 1px dashed #e3caca;
        border-radius: 18px;
        padding: 18px;
        color: var(--dash-muted);
        background: #fffafa;
        text-align: center;
        font-size: 14px;
    }

    @media (max-width: 1200px) {
        .alumni-modern-dashboard .metrics-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .alumni-modern-dashboard .dashboard-grid-modern,
        .alumni-modern-dashboard .hero-inner {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 700px) {
        .alumni-modern-dashboard .dash-hero,
        .alumni-modern-dashboard .modern-card {
            border-radius: 22px;
            padding: 20px;
        }
        .alumni-modern-dashboard .metrics-grid,
        .alumni-modern-dashboard .profile-list-modern {
            grid-template-columns: 1fr;
        }
        .alumni-modern-dashboard .hero-actions-modern,
        .alumni-modern-dashboard .career-actions {
            display: grid;
        }
        .alumni-modern-dashboard .btn-hero-primary,
        .alumni-modern-dashboard .btn-hero-light,
        .alumni-modern-dashboard .btn-hero-ghost,
        .alumni-modern-dashboard .btn-modern-maroon,
        .alumni-modern-dashboard .btn-modern-outline,
        .alumni-modern-dashboard .btn-modern-disabled {
            width: 100%;
        }
        .alumni-modern-dashboard .card-header-modern,
        .alumni-modern-dashboard .career-feature-top {
            flex-direction: column;
        }
    }
</style>

<div class="alumni-modern-dashboard fade-in">
    <section class="dash-hero">
        <div class="hero-inner">
            <div>
                <span class="eyebrow">Alumni Portal</span>
                <h2 class="hero-heading">Welcome back, <?= htmlspecialchars($_SESSION['first_name'] ?? 'Alumni') ?>.</h2>
                <p class="hero-copy">
                    Manage your alumni profile, track your tracer study progress, view job opportunities,
                    and keep your career timeline updated in one place.
                </p>

                <div class="hero-actions-modern">
                    <a href="edit_profile.php" class="btn-hero-primary">Complete Profile</a>

                    <?php if ($activeTracerSurvey): ?>
                        <a href="update_career.php" class="btn-hero-light"><?= htmlspecialchars($careerButtonText) ?></a>
                    <?php else: ?>
                        <span class="btn-hero-light" style="opacity: .65; cursor: not-allowed;">No Active Tracer Survey</span>
                    <?php endif; ?>

                 
                    <a href="recommended_jobs.php" class="btn-hero-ghost">View Jobs</a>
                    <a href="job_invitations.php" class="btn-hero-ghost">
                        Job Invitations<?= $newJobInviteCount > 0 ? ' (' . $newJobInviteCount . ')' : '' ?>
                    </a>
                </div>
            </div>

            <aside class="hero-side-card">
                <div class="completion-top">
                    <div>
                        <div class="completion-label">Profile Completion</div>
                        <div class="completion-value"><?= $completion ?>%</div>
                    </div>
                    <div class="completion-pill"><?= htmlspecialchars($profileStatusLabel) ?></div>
                </div>

                <div class="modern-progress" style="--progress-width: <?= $completion ?>%;">
                    <span></span>
                </div>

                <div class="completion-note">
                    <?php if ($completion >= 100): ?>
                        Your profile is complete and ready for recommendations, job matching, and tracer updates.
                    <?php else: ?>
                        <?= $missingFields ?> required <?= $missingFields === 1 ? 'field is' : 'fields are' ?> still missing. Complete your profile to improve your job matching results.
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </section>

    <section class="metrics-grid">
        <div class="metric-card">
            <div class="metric-head">
                <div class="metric-title">Profile</div>
            
            </div>
            <div class="metric-value"><?= $completion ?>%</div>
            <div class="metric-note"><?= htmlspecialchars($profileStatusLabel) ?> profile status</div>
        </div>

        <div class="metric-card">
            <div class="metric-head">
                <div class="metric-title">Applications</div>
               
            </div>
            <div class="metric-value"><?= $applicationCount ?></div>
            <div class="metric-note">
                <?= $latestApplication ? 'Latest: ' . htmlspecialchars($latestApplication['status']) : 'No applications yet' ?>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-head">
                <div class="metric-title">Recommended Jobs</div>
             
            </div>
            <div class="metric-value"><?= $recommendedJobsCount ?></div>
            <div class="metric-note">Open opportunities matched to your profile</div>
        </div>

        <div class="metric-card">
            <div class="metric-head">
                <div class="metric-title">Job Invitations</div>
           
            </div>
            <div class="metric-value"><?= $jobInviteCount ?></div>
            <div class="metric-note">
                <?= $newJobInviteCount > 0 ? $newJobInviteCount . ' new company invite(s)' : 'Company invitations to apply' ?>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-head">
                <div class="metric-title">Tracer Status</div>
                
            </div>
            <div class="metric-value"><?= htmlspecialchars($tracerStatusLabel) ?></div>
            <div class="metric-note"><?= htmlspecialchars($tracerStatusNote) ?></div>
        </div>
    </section>

    <section class="dashboard-grid-modern">
        <div>
            <div class="career-feature">
                <div class="career-feature-top">
                    <div>
                        <h3><?= $activeTracerSurvey ? htmlspecialchars($careerButtonText) : 'Tracer Survey Unavailable' ?></h3>
                        <p>
                            <?php if ($activeTracerSurvey): ?>
                                <?= htmlspecialchars($careerButtonNote) ?> Your submissions are saved as career snapshots for your timeline.
                            <?php else: ?>
                                There is currently no active tracer survey. Please wait for the administrator to publish one.
                            <?php endif; ?>
                        </p>
                    </div>
                   
                </div>

                <div class="career-actions">
                    <?php if ($activeTracerSurvey): ?>
                        <a href="update_career.php" class="btn-modern-maroon"><?= htmlspecialchars($careerButtonText) ?></a>
                    <?php else: ?>
                        <span class="btn-modern-disabled">No Active Tracer Survey</span>
                    <?php endif; ?>
                    <a href="career_timeline.php" class="btn-modern-outline">View Career Timeline</a>
                </div>
            </div>

            <div class="modern-card">
                <div class="card-header-modern">
                    <div>
                        <h3 class="card-title-modern">Profile Summary</h3>
                        <div class="card-subtitle-modern">Your academic and contact information</div>
                    </div>
                    <span class="status-badge-modern"><?= htmlspecialchars($profileStatusLabel) ?></span>
                </div>

                <div class="profile-list-modern">
                    <div class="profile-item-modern">
                        <div class="profile-key-modern">Full Name</div>
                        <div class="profile-value-modern"><?= htmlspecialchars($displayName) ?></div>
                    </div>

                    <div class="profile-item-modern">
                        <div class="profile-key-modern">Email</div>
                        <div class="profile-value-modern"><?= htmlspecialchars($profile['email'] ?? '—') ?></div>
                    </div>

                    <div class="profile-item-modern">
                        <div class="profile-key-modern">Student ID</div>
                        <div class="profile-value-modern"><?= htmlspecialchars($profile['student_id'] ?? '—') ?></div>
                    </div>

                    <div class="profile-item-modern">
                        <div class="profile-key-modern">Course</div>
                        <div class="profile-value-modern"><?= htmlspecialchars($displayCourse) ?></div>
                    </div>

                    <div class="profile-item-modern">
                        <div class="profile-key-modern">Major</div>
                        <div class="profile-value-modern"><?= htmlspecialchars($profile['major'] ?? '—') ?></div>
                    </div>

                    <div class="profile-item-modern">
                        <div class="profile-key-modern">Graduation Year</div>
                        <div class="profile-value-modern"><?= htmlspecialchars($profile['graduation_year'] ?? '—') ?></div>
                    </div>

                    <div class="profile-item-modern">
                        <div class="profile-key-modern">Employment Status</div>
                        <div class="profile-value-modern"><?= htmlspecialchars($profile['employment_status'] ?? '—') ?></div>
                    </div>

                    <div class="profile-item-modern">
                        <div class="profile-key-modern">Career Updates</div>
                        <div class="profile-value-modern">
                            <?= $totalCareerUpdates ?> <?= $totalCareerUpdates === 1 ? 'snapshot' : 'snapshots' ?>
                            <?= $latestCareerUpdate ? '<br><span style="color:#6b7280;font-size:13px;">Last: ' . htmlspecialchars(date('M j, Y', strtotime($latestCareerUpdate))) . '</span>' : '' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <aside class="right-stack">
            <div class="modern-card">
                <div class="card-header-modern">
                    <div>
                        <h3 class="card-title-modern">Quick Actions</h3>
                        <div class="card-subtitle-modern">Common tasks and next steps</div>
                    </div>
                </div>

                <div class="action-list-modern">
                    <a href="edit_profile.php" class="action-item-modern">
                        <div class="action-left-modern">
           
                            <div>
                                <span class="action-title-modern">Edit Profile</span>
                                <span class="action-desc-modern">Update academic and personal details</span>
                            </div>
                        </div>
                        <span class="action-arrow-modern">&rarr;</span>
                    </a>

                    <?php if ($activeTracerSurvey): ?>
                        <a href="update_career.php" class="action-item-modern">
                            <div class="action-left-modern">
                             
                                <div>
                                    <span class="action-title-modern"><?= htmlspecialchars($careerButtonText) ?></span>
                                    <span class="action-desc-modern"><?= htmlspecialchars($careerButtonNote) ?></span>
                                </div>
                            </div>
                            <span class="action-arrow-modern">&rarr;</span>
                        </a>
                    <?php else: ?>
                        <div class="action-item-modern" style="opacity:.65;">
                            <div class="action-left-modern">
                            
                                <div>
                                    <span class="action-title-modern">No Active Tracer Survey</span>
                                    <span class="action-desc-modern">Please wait for the administrator to publish one.</span>
                                </div>
                            </div>
                            <span class="action-arrow-modern">--</span>
                        </div>
                    <?php endif; ?>

                    <a href="career_timeline.php" class="action-item-modern">
                        <div class="action-left-modern">
                            
                            <div>
                                <span class="action-title-modern">Career Timeline</span>
                                <span class="action-desc-modern">Review your tracer history and career snapshots</span>
                            </div>
                        </div>
                        <span class="action-arrow-modern">&rarr;</span>
                    </a>

                    <a href="recommended_jobs.php" class="action-item-modern">
                        <div class="action-left-modern">
                            
                            <div>
                                <span class="action-title-modern">Recommended Jobs</span>
                                <span class="action-desc-modern">Browse opportunities matched to your profile</span>
                            </div>
                        </div>
                        <span class="action-arrow-modern">&rarr;</span>
                    </a>

                    <a href="job_invitations.php" class="action-item-modern">
                        <div class="action-left-modern">
                   
                            <div>
                                <span class="action-title-modern">Job Invitations</span>
                                <span class="action-desc-modern">
                                    <?= $newJobInviteCount > 0 ? $newJobInviteCount . ' new invite(s) from partner companies' : 'View company invitations to apply' ?>
                                </span>
                            </div>
                        </div>
                        <span class="action-arrow-modern">&rarr;</span>
                    </a>

                    <a href="applications.php" class="action-item-modern">
                        <div class="action-left-modern">
                       
                            <div>
                                <span class="action-title-modern">My Applications</span>
                                <span class="action-desc-modern">Track submitted job applications</span>
                            </div>
                        </div>
                        <span class="action-arrow-modern">&rarr;</span>
                    </a>
                </div>
            </div>

            <div class="modern-card">
                <div class="card-header-modern">
                    <div>
                        <h3 class="card-title-modern">Latest Updates</h3>
                        <div class="card-subtitle-modern">Announcements and events for alumni</div>
                    </div>
                </div>

                <?php if (empty($latestAnnouncements)): ?>
                    <div class="empty-state-modern">No announcements available yet.</div>
                <?php else: ?>
                    <div class="announcement-list-modern">
                        <?php foreach ($latestAnnouncements as $item): ?>
                            <a href="announcements.php" class="announcement-item-modern">
                                <span class="announcement-title-modern"><?= htmlspecialchars($item['title']) ?></span>
                                <span class="announcement-meta-modern">
                                    <?= !empty($item['event_date']) ? date('M j, Y', strtotime($item['event_date'])) : date('M j, Y', strtotime($item['created_at'])) ?>
                                    <?= !empty($item['location']) ? ' • ' . htmlspecialchars($item['location']) : '' ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </aside>
    </section>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
