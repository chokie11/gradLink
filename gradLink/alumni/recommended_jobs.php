<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';
require_once '../includes/job_matcher.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];
$refreshAi = isset($_GET['refresh_ai']) && $_GET['refresh_ai'] === '1';

/*
|--------------------------------------------------------------------------
| Load alumni profile
|--------------------------------------------------------------------------
*/

$profileStmt = $pdo->prepare("
    SELECT ap.*, u.first_name, u.last_name, u.email
    FROM alumni_profiles ap
    INNER JOIN users u ON ap.user_id = u.id
    WHERE ap.user_id = ?
    LIMIT 1
");
$profileStmt->execute([$user_id]);
$alumniProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);

if (!$alumniProfile) {
    header('Location: edit_profile.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load open jobs
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT 
        jp.*,
        pp.company_name,
        pp.industry_type,
        pp.website
    FROM job_posts jp
    INNER JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    WHERE jp.status = 'Open'
      AND (jp.deadline IS NULL OR jp.deadline >= CURDATE())
    ORDER BY 
        CASE WHEN jp.deadline IS NULL THEN 1 ELSE 0 END,
        jp.deadline ASC,
        jp.created_at DESC
");
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Load already applied job IDs
|--------------------------------------------------------------------------
*/
$appliedStmt = $pdo->prepare("
    SELECT job_post_id
    FROM applications
    WHERE alumni_id = ?
");
$appliedStmt->execute([$user_id]);
$appliedJobIds = array_map('intval', $appliedStmt->fetchAll(PDO::FETCH_COLUMN));

/*
|--------------------------------------------------------------------------
| Load company job invitations for this alumni
|--------------------------------------------------------------------------
| This keeps the page safe even if the invitation table has not been installed.
|--------------------------------------------------------------------------
*/

$jobInvitationsByJob = [];
$activeInvitationCount = 0;
$newInvitationCount = 0;

try {
    $inviteStmt = $pdo->prepare("
        SELECT job_post_id, status, match_score, message, created_at
        FROM job_invitations
        WHERE alumni_id = ?
          AND status <> 'Dismissed'
    ");
    $inviteStmt->execute([$user_id]);
    $inviteRows = $inviteStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($inviteRows as $inviteRow) {
        $inviteJobId = (int) ($inviteRow['job_post_id'] ?? 0);
        if ($inviteJobId > 0) {
            $jobInvitationsByJob[$inviteJobId] = $inviteRow;
            if (in_array($inviteRow['status'] ?? '', ['Sent', 'Viewed'], true)) {
                $activeInvitationCount++;
            }
            if (($inviteRow['status'] ?? '') === 'Sent') {
                $newInvitationCount++;
            }
        }
    }
} catch (Exception $e) {
    $jobInvitationsByJob = [];
    $activeInvitationCount = 0;
    $newInvitationCount = 0;
}


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

$displayCourse = $courseLabels[$alumniProfile['course'] ?? ''] ?? ($alumniProfile['course'] ?? '—');

/*
|--------------------------------------------------------------------------
| AI-assisted job recommendation
|--------------------------------------------------------------------------
| We only call Gemini when there is no saved recommendation or when the user
| clicks Refresh AI Match. Results are saved in job_recommendations.
|--------------------------------------------------------------------------
*/
foreach ($jobs as &$job) {
    $match = getJobRecommendation($pdo, (int) $user_id, $alumniProfile, $job, $refreshAi);

    $job['match_score'] = (int) ($match['match_score'] ?? 0);
    $job['match_level'] = $match['match_level'] ?? getMatchLevel($job['match_score']);
    $job['ai_reason'] = $match['ai_reason'] ?? '';
    $job['missing_skills'] = $match['missing_skills'] ?? '';
    $job['suggested_action'] = $match['suggested_action'] ?? '';
    $job['match_source'] = $match['source'] ?? 'Matcher';
}
unset($job);

/*
|--------------------------------------------------------------------------
| Sort by match score first, then deadline
|--------------------------------------------------------------------------
*/
usort($jobs, function ($a, $b) {
    if ($a['match_score'] === $b['match_score']) {
        return strcmp((string) ($a['deadline'] ?? '9999-12-31'), (string) ($b['deadline'] ?? '9999-12-31'));
    }
    return $b['match_score'] <=> $a['match_score'];
});

/*
|--------------------------------------------------------------------------
| Load applications
|--------------------------------------------------------------------------
*/
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

$totalApplications = count($applications);
$totalJobs = count($jobs);
$bestJob = $jobs[0] ?? null;
$bestScore = $bestJob ? (int) $bestJob['match_score'] : 0;
$averageScore = $totalJobs > 0 ? round(array_sum(array_column($jobs, 'match_score')) / $totalJobs) : 0;
$appliedOpenCount = 0;
$urgentDeadlineCount = 0;

foreach ($jobs as $job) {
    if (in_array((int) $job['id'], $appliedJobIds, true)) {
        $appliedOpenCount++;
    }

    if (!empty($job['deadline'])) {
        $today = new DateTime('today');
        $deadlineDate = new DateTime($job['deadline']);
        $daysLeft = (int) $today->diff($deadlineDate)->format('%r%a');
        if ($daysLeft >= 0 && $daysLeft <= 7) {
            $urgentDeadlineCount++;
        }
    }
}

function rjShortText($text, $limit = 220) {
    $text = trim((string) $text);
    if ($text === '') {
        return 'No job description provided.';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function rjMatchClass($score) {
    $score = (int) $score;

    if ($score >= 80) {
        return 'strong';
    }

    if ($score >= 60) {
        return 'good';
    }

    if ($score >= 40) {
        return 'fair';
    }

    return 'low';
}

function rjDeadlineInfo($deadline) {
    if (empty($deadline)) {
        return [
            'label' => 'Open until filled',
            'class' => 'neutral',
            'date' => 'No fixed deadline'
        ];
    }

    $today = new DateTime('today');
    $deadlineDate = new DateTime($deadline);
    $daysLeft = (int) $today->diff($deadlineDate)->format('%r%a');
    $dateLabel = date('M j, Y', strtotime($deadline));

    if ($daysLeft < 0) {
        return [
            'label' => 'Closed',
            'class' => 'danger',
            'date' => $dateLabel
        ];
    }

    if ($daysLeft === 0) {
        return [
            'label' => 'Deadline today',
            'class' => 'danger',
            'date' => $dateLabel
        ];
    }

    if ($daysLeft === 1) {
        return [
            'label' => '1 day left',
            'class' => 'warning',
            'date' => $dateLabel
        ];
    }

    if ($daysLeft <= 7) {
        return [
            'label' => $daysLeft . ' days left',
            'class' => 'warning',
            'date' => $dateLabel
        ];
    }

    return [
        'label' => $daysLeft . ' days left',
        'class' => 'success',
        'date' => $dateLabel
    ];
}

function rjBucket($score) {
    $score = (int) $score;

    if ($score >= 80) {
        return 'strong';
    }

    if ($score >= 60) {
        return 'good';
    }

    return 'starter';
}

$needsProfileImprovement = empty($alumniProfile['skills']) && empty($alumniProfile['preferred_job_titles']) && empty($alumniProfile['career_interests']);

$pageTitle = 'Recommended Jobs';
$pageSubtitle = 'Explore real job opportunities matched to your enhanced alumni profile.';
$activePage = 'jobs';

require_once '../includes/dashboard_header.php';
?>

<style>
    :root {
        --rj-maroon: #800000;
        --rj-maroon-dark: #5f0000;
        --rj-maroon-soft: #fff4f4;
        --rj-gold: #f6c667;
        --rj-ink: #241818;
        --rj-muted: #766868;
        --rj-line: #eee1e1;
        --rj-card: #ffffff;
        --rj-bg: #fbf7f7;
        --rj-shadow: 0 18px 45px rgba(80, 0, 0, 0.08);
    }

    .jobs-modern-wrap {
        display: grid;
        gap: 22px;
    }

    .jobs-hero-modern {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 30px;
        background:
            radial-gradient(circle at top right, rgba(246, 198, 103, 0.36), transparent 30%),
            linear-gradient(135deg, var(--rj-maroon), var(--rj-maroon-dark));
        color: #fff;
        box-shadow: 0 22px 60px rgba(128, 0, 0, 0.22);
    }

    .jobs-hero-modern::after {
        content: "";
        position: absolute;
        width: 260px;
        height: 260px;
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 50%;
        right: -70px;
        bottom: -100px;
    }

    .jobs-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.6fr);
        gap: 26px;
        align-items: stretch;
    }

    .jobs-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.18);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .7px;
        text-transform: uppercase;
    }

    .jobs-hero-title {
        margin: 16px 0 10px;
        font-size: clamp(30px, 4vw, 46px);
        line-height: 1.02;
        font-weight: 900;
        letter-spacing: -1.2px;
    }

    .jobs-hero-text {
        max-width: 780px;
        color: rgba(255, 255, 255, 0.84);
        font-size: 15.5px;
        line-height: 1.7;
    }

    .jobs-hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 22px;
    }

    .jobs-btn-primary,
    .jobs-btn-light,
    .jobs-btn-soft {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 11px 16px;
        border-radius: 14px;
        text-decoration: none;
        font-weight: 800;
        border: 1px solid transparent;
        transition: .2s ease;
        font-size: 14px;
    }

    .jobs-btn-primary {
        background: linear-gradient(135deg, var(--rj-maroon), var(--rj-maroon-dark));
        color: #fff;
        box-shadow: 0 12px 28px rgba(128, 0, 0, 0.20);
    }

    .jobs-btn-primary:hover {
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 16px 36px rgba(128, 0, 0, 0.26);
    }

    .jobs-btn-light {
        background: #fff;
        color: var(--rj-maroon);
        border-color: rgba(255, 255, 255, .34);
    }

    .jobs-btn-light:hover {
        color: var(--rj-maroon-dark);
        transform: translateY(-1px);
    }

    .jobs-btn-soft {
        background: rgba(255, 255, 255, 0.13);
        color: #fff;
        border-color: rgba(255, 255, 255, 0.20);
    }

    .jobs-btn-soft:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.20);
        transform: translateY(-1px);
    }

    .jobs-hero-side {
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 24px;
        padding: 22px;
        backdrop-filter: blur(10px);
        display: grid;
        gap: 16px;
    }

    .jobs-hero-side .mini-label {
        color: rgba(255, 255, 255, 0.72);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .8px;
    }

    .jobs-hero-side .mini-value {
        font-size: 44px;
        font-weight: 900;
        letter-spacing: -1px;
        line-height: 1;
    }

    .jobs-profile-pill {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        padding: 13px;
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.12);
    }

    .jobs-profile-pill .avatar-dot {
        width: 40px;
        height: 40px;
        border-radius: 14px;
        background: #fff;
        color: var(--rj-maroon);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        flex: 0 0 40px;
    }

    .jobs-profile-pill strong {
        display: block;
        line-height: 1.2;
    }

    .jobs-profile-pill span {
        display: block;
        color: rgba(255, 255, 255, .74);
        font-size: 13px;
        margin-top: 4px;
    }

    .jobs-stats-modern {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
    }

    .jobs-stat-card-modern {
        background: var(--rj-card);
        border: 1px solid var(--rj-line);
        border-radius: 22px;
        padding: 18px;
        box-shadow: var(--rj-shadow);
        position: relative;
        overflow: hidden;
    }

    .jobs-stat-card-modern::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--rj-maroon), #c9973d);
        opacity: .9;
    }

    .jobs-stat-label {
        color: var(--rj-muted);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .7px;
    }

    .jobs-stat-value {
        margin-top: 8px;
        color: var(--rj-ink);
        font-size: 30px;
        font-weight: 900;
        line-height: 1;
    }

    .jobs-stat-note {
        margin-top: 8px;
        color: var(--rj-muted);
        font-size: 13px;
    }

    .jobs-profile-alert {
        border-radius: 22px;
        padding: 18px;
        background: linear-gradient(135deg, #fff8ec, #fff4f4);
        border: 1px solid #f4dfbb;
        box-shadow: 0 14px 35px rgba(115, 69, 0, 0.08);
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: center;
        flex-wrap: wrap;
    }

    .jobs-profile-alert strong {
        color: var(--rj-ink);
    }

    .jobs-profile-alert p {
        margin: 4px 0 0;
        color: var(--rj-muted);
    }

    .jobs-panel-modern {
        background: var(--rj-card);
        border: 1px solid var(--rj-line);
        border-radius: 26px;
        box-shadow: var(--rj-shadow);
        overflow: hidden;
    }

    .jobs-panel-head {
        padding: 22px 22px 18px;
        border-bottom: 1px solid var(--rj-line);
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        flex-wrap: wrap;
    }

    .jobs-panel-title {
        color: var(--rj-ink);
        font-size: 21px;
        font-weight: 900;
        letter-spacing: -.3px;
        margin: 0;
    }

    .jobs-panel-subtitle {
        margin-top: 4px;
        color: var(--rj-muted);
        font-size: 13px;
    }

    .jobs-toolbar {
        padding: 16px 22px;
        display: grid;
        grid-template-columns: minmax(220px, 1fr) auto;
        gap: 14px;
        align-items: center;
        background: #fffafa;
        border-bottom: 1px solid var(--rj-line);
    }

    .jobs-search {
        position: relative;
    }

    .jobs-search input {
        width: 100%;
        border: 1px solid #eadada;
        border-radius: 16px;
        padding: 12px 14px 12px 42px;
        outline: none;
        transition: .2s ease;
        background: #fff;
        color: var(--rj-ink);
    }

    .jobs-search input:focus {
        border-color: rgba(128, 0, 0, .42);
        box-shadow: 0 0 0 4px rgba(128, 0, 0, .08);
    }

    .jobs-search span {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--rj-muted);
    }

    .jobs-filter-group {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: flex-end;
    }

    .jobs-filter-btn {
        border: 1px solid #eadada;
        background: #fff;
        color: var(--rj-muted);
        border-radius: 999px;
        padding: 9px 12px;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
        transition: .2s ease;
    }

    .jobs-filter-btn:hover,
    .jobs-filter-btn.active {
        background: var(--rj-maroon);
        color: #fff;
        border-color: var(--rj-maroon);
    }

    .jobs-list-modern {
        padding: 20px 22px 24px;
        display: grid;
        gap: 16px;
    }

    .job-card-modern {
        border: 1px solid var(--rj-line);
        border-radius: 24px;
        padding: 20px;
        background: #fff;
        transition: .2s ease;
        position: relative;
        overflow: hidden;
    }

    .job-card-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 36px rgba(128, 0, 0, 0.10);
        border-color: #e5cccc;
    }

    .job-card-top {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 18px;
        align-items: flex-start;
    }

    .job-title-row {
        display: flex;
        gap: 12px;
        align-items: flex-start;
    }

    .company-icon {
        width: 46px;
        height: 46px;
        border-radius: 16px;
        background: var(--rj-maroon-soft);
        color: var(--rj-maroon);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        flex: 0 0 46px;
        border: 1px solid #f0dddd;
    }

    .job-title {
        font-size: 18px;
        color: var(--rj-ink);
        font-weight: 900;
        line-height: 1.25;
        margin: 0;
    }

    .job-company-line {
        margin-top: 6px;
        color: var(--rj-muted);
        font-size: 13px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .job-match-box {
        min-width: 132px;
        border-radius: 20px;
        padding: 12px;
        text-align: center;
        background: #fff8f8;
        border: 1px solid #efdada;
    }

    .match-score {
        font-size: 27px;
        font-weight: 900;
        line-height: 1;
    }

    .match-score.strong { color: #166534; }
    .match-score.good { color: #800000; }
    .match-score.fair { color: #b45309; }
    .match-score.low { color: #6b7280; }

    .match-level {
        margin-top: 5px;
        color: var(--rj-muted);
        font-size: 12px;
        font-weight: 800;
    }

    .job-meta-pills {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin: 16px 0 0;
    }

    .job-pill {
        border-radius: 999px;
        padding: 7px 10px;
        background: #faf4f4;
        color: #5f4a4a;
        font-size: 12px;
        font-weight: 800;
        border: 1px solid #f0e1e1;
    }

    .job-pill.deadline-danger {
        background: #fff1f2;
        color: #be123c;
        border-color: #fecdd3;
    }

    .job-pill.deadline-warning {
        background: #fffbeb;
        color: #b45309;
        border-color: #fde68a;
    }

    .job-pill.deadline-success {
        background: #f0fdf4;
        color: #166534;
        border-color: #bbf7d0;
    }

    .job-pill.deadline-neutral {
        background: #f8fafc;
        color: #475569;
        border-color: #e2e8f0;
    }

    .job-pill.invited-company {
        background: #fff7ed;
        color: #c2410c;
        border-color: #fed7aa;
    }

    .invite-note-modern {
        margin-top: 14px;
        border-radius: 18px;
        padding: 13px 14px;
        background: linear-gradient(135deg, #fff7ed, #ffffff);
        border: 1px solid #fed7aa;
        color: #7c2d12;
        font-size: 13.5px;
        line-height: 1.55;
    }

    .invite-note-modern strong {
        display: block;
        color: #9a3412;
        font-weight: 900;
        margin-bottom: 3px;
    }

    .job-description {
        color: #554848;
        line-height: 1.65;
        margin-top: 14px;
        font-size: 14px;
    }

    .ai-box-modern {
        margin-top: 16px;
        border-radius: 20px;
        border: 1px solid #eedbdb;
        background: linear-gradient(135deg, #fff8f8, #ffffff);
        padding: 15px;
    }

    .ai-title {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--rj-maroon);
        font-weight: 900;
        margin-bottom: 6px;
    }

    .ai-text {
        color: var(--rj-muted);
        font-size: 13.5px;
        line-height: 1.6;
    }

    .ai-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-top: 12px;
    }

    .ai-mini {
        background: #fff;
        border: 1px solid #f0e2e2;
        border-radius: 16px;
        padding: 12px;
    }

    .ai-mini strong {
        display: block;
        color: var(--rj-ink);
        font-size: 12px;
        margin-bottom: 4px;
    }

    .ai-mini span {
        color: var(--rj-muted);
        font-size: 13px;
        line-height: 1.5;
    }

    .job-actions-modern {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        padding-top: 16px;
        margin-top: 16px;
        border-top: 1px solid #f0e3e3;
    }

    .job-actions-left {
        color: var(--rj-muted);
        font-size: 12px;
        font-weight: 700;
    }

    .job-actions-right {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .jobs-empty-state {
        padding: 42px 20px;
        text-align: center;
        color: var(--rj-muted);
    }

    .jobs-empty-icon {
        width: 68px;
        height: 68px;
        border-radius: 24px;
        background: var(--rj-maroon-soft);
        color: var(--rj-maroon);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        margin-bottom: 12px;
    }

    .jobs-empty-state h3 {
        color: var(--rj-ink);
        font-weight: 900;
        margin-bottom: 6px;
    }

    @media (max-width: 992px) {
        .jobs-hero-grid,
        .jobs-toolbar,
        .job-card-top {
            grid-template-columns: 1fr;
        }

        .jobs-stats-modern {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .jobs-filter-group {
            justify-content: flex-start;
        }

        .job-match-box {
            width: 100%;
            text-align: left;
        }
    }

    @media (max-width: 576px) {
        .jobs-hero-modern,
        .job-card-modern,
        .jobs-panel-head,
        .jobs-toolbar,
        .jobs-list-modern {
            padding-left: 16px;
            padding-right: 16px;
        }

        .jobs-stats-modern,
        .ai-grid {
            grid-template-columns: 1fr;
        }

        .jobs-hero-title {
            font-size: 31px;
        }
    }
</style>

<div class="jobs-modern-wrap">
    <section class="jobs-hero-modern">
        <div class="jobs-hero-grid">
            <div>
                <span class="jobs-eyebrow">AI-Assisted Job Matching</span>
                <h1 class="jobs-hero-title">Recommended jobs for you</h1>
                <div class="jobs-hero-text">
                    Review opportunities ranked by your profile, skills, preferred roles, career interests,
                    location, and work setup preference. Keep your profile updated to improve your match quality.
                </div>

                <div class="jobs-hero-actions">
                    <a href="applications.php" class="jobs-btn-light">My Applications</a>
                    <a href="job_invitations.php" class="jobs-btn-soft">
                        Job Invitations<?= $newInvitationCount > 0 ? ' (' . $newInvitationCount . ')' : '' ?>
                    </a>
                    <a href="edit_profile.php" class="jobs-btn-soft">Improve My Profile</a>
                    <a href="recommended_jobs.php?refresh_ai=1" class="jobs-btn-soft">Refresh AI Match</a>
                </div>
            </div>

            <aside class="jobs-hero-side">
                <div>
                    <div class="mini-label">Open Opportunities</div>
                    <div class="mini-value"><?= $totalJobs ?></div>
                </div>

                <div class="jobs-profile-pill">
                    <div class="avatar-dot">
                        <?= htmlspecialchars(strtoupper(substr($alumniProfile['first_name'] ?? 'A', 0, 1))) ?>
                    </div>
                    <div>
                        <strong><?= htmlspecialchars(($alumniProfile['first_name'] ?? '') . ' ' . ($alumniProfile['last_name'] ?? '')) ?></strong>
                        <span>
                            <?= htmlspecialchars($displayCourse) ?>
                            <?= !empty($alumniProfile['major']) ? ' • ' . htmlspecialchars($alumniProfile['major']) : '' ?>
                        </span>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <?php if ($needsProfileImprovement): ?>
        <div class="jobs-profile-alert">
            <div>
                <strong>Improve your job recommendations</strong>
                <p>Add your skills, preferred job titles, and career interests so the matching system can rank jobs more accurately.</p>
            </div>
            <a href="edit_profile.php" class="jobs-btn-primary">Update Profile</a>
        </div>
    <?php endif; ?>

    <section class="jobs-stats-modern">
        <div class="jobs-stat-card-modern">
            <div class="jobs-stat-label">Open Jobs</div>
            <div class="jobs-stat-value"><?= $totalJobs ?></div>
            <div class="jobs-stat-note">Available opportunities</div>
        </div>

        <div class="jobs-stat-card-modern">
            <div class="jobs-stat-label">Best Match</div>
            <div class="jobs-stat-value"><?= $bestScore ?>%</div>
            <div class="jobs-stat-note"><?= $bestJob ? htmlspecialchars($bestJob['match_level']) . ' match' : 'No matches yet' ?></div>
        </div>

        <div class="jobs-stat-card-modern">
            <div class="jobs-stat-label">Company Invites</div>
            <div class="jobs-stat-value"><?= $activeInvitationCount ?></div>
            <div class="jobs-stat-note"><?= $newInvitationCount > 0 ? $newInvitationCount . ' new invitation(s)' : 'Invited opportunities' ?></div>
        </div>

        <div class="jobs-stat-card-modern">
            <div class="jobs-stat-label">Average Match</div>
            <div class="jobs-stat-value"><?= $averageScore ?>%</div>
            <div class="jobs-stat-note">Across all open jobs</div>
        </div>

        <div class="jobs-stat-card-modern">
            <div class="jobs-stat-label">Applications Sent</div>
            <div class="jobs-stat-value"><?= $totalApplications ?></div>
            <div class="jobs-stat-note"><?= $appliedOpenCount ?> from current open jobs</div>
        </div>
    </section>

    <section class="jobs-panel-modern">
        <div class="jobs-panel-head">
            <div>
                <h2 class="jobs-panel-title">Job Opportunities</h2>
                <div class="jobs-panel-subtitle">
                    Sorted by best match first. <?= $urgentDeadlineCount > 0 ? $urgentDeadlineCount . ' job(s) have deadlines within 7 days.' : 'No urgent deadlines within 7 days.' ?>
                </div>
            </div>
            <span class="job-pill">Profile + Skills + AI</span>
        </div>

        <?php if (empty($jobs)): ?>
            <div class="jobs-empty-state">
                <div class="jobs-empty-icon">⌕</div>
                <h3>No open job posts available</h3>
                <p>Please check again later or update your profile so you are ready when new opportunities are posted.</p>
                <a href="edit_profile.php" class="jobs-btn-primary mt-2">Improve My Profile</a>
            </div>
        <?php else: ?>
            <div class="jobs-toolbar">
                <div class="jobs-search">
                    <span>⌕</span>
                    <input type="text" id="jobSearchInput" placeholder="Search by job title, company, location, or employment type...">
                </div>

                <div class="jobs-filter-group" aria-label="Job filters">
                    <button type="button" class="jobs-filter-btn active" data-filter="all">All</button>
                    <button type="button" class="jobs-filter-btn" data-filter="strong">Strong Match</button>
                    <button type="button" class="jobs-filter-btn" data-filter="good">Good Match</button>
                    <button type="button" class="jobs-filter-btn" data-filter="invited">Invited</button>
                    <button type="button" class="jobs-filter-btn" data-filter="applied">Applied</button>
                    <button type="button" class="jobs-filter-btn" data-filter="not-applied">Not Applied</button>
                </div>
            </div>

            <div class="jobs-list-modern" id="jobsListModern">
                <?php foreach ($jobs as $job): ?>
                    <?php
                        $jobId = (int) $job['id'];
                        $isApplied = in_array($jobId, $appliedJobIds, true);
                        $invitation = $jobInvitationsByJob[$jobId] ?? null;
                        $isInvited = $invitation && !in_array(($invitation['status'] ?? ''), ['Dismissed'], true);
                        $inviteStatus = $invitation['status'] ?? '';
                        $deadlineInfo = rjDeadlineInfo($job['deadline'] ?? null);
                        $matchClass = rjMatchClass($job['match_score'] ?? 0);
                        $bucket = rjBucket($job['match_score'] ?? 0);
                        $companyInitial = strtoupper(substr($job['company_name'] ?? 'C', 0, 1));
                        $searchText = strtolower(trim(
                            ($job['title'] ?? '') . ' ' .
                            ($job['company_name'] ?? '') . ' ' .
                            ($job['location'] ?? '') . ' ' .
                            ($job['employment_type'] ?? '') . ' ' .
                            ($job['industry_type'] ?? '')
                        ));
                    ?>

                    <article
                        class="job-card-modern"
                        data-bucket="<?= htmlspecialchars($bucket) ?>"
                        data-applied="<?= $isApplied ? 'applied' : 'not-applied' ?>"
                        data-invited="<?= $isInvited ? 'invited' : 'not-invited' ?>"
                        data-search="<?= htmlspecialchars($searchText) ?>"
                    >
                        <div class="job-card-top">
                            <div class="job-title-row">
                                <div class="company-icon"><?= htmlspecialchars($companyInitial) ?></div>
                                <div>
                                    <h3 class="job-title"><?= htmlspecialchars($job['title'] ?? 'Untitled Job') ?></h3>
                                    <div class="job-company-line">
                                        <span><?= htmlspecialchars($job['company_name'] ?? 'Company') ?></span>
                                        <?php if (!empty($job['industry_type'])): ?>
                                            <span>• <?= htmlspecialchars($job['industry_type']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="job-match-box">
                                <div class="match-score <?= htmlspecialchars($matchClass) ?>">
                                    <?= (int) ($job['match_score'] ?? 0) ?>%
                                </div>
                                <div class="match-level"><?= htmlspecialchars($job['match_level'] ?? 'Match') ?> Match</div>
                            </div>
                        </div>

                        <div class="job-meta-pills">
                            <span class="job-pill"><?= htmlspecialchars($job['employment_type'] ?? 'Employment type not specified') ?></span>
                            <span class="job-pill"><?= htmlspecialchars($job['location'] ?? 'Location not specified') ?></span>
                            <span class="job-pill"><?= !empty($job['salary_range']) ? htmlspecialchars($job['salary_range']) : 'Salary not specified' ?></span>
                            <span class="job-pill deadline-<?= htmlspecialchars($deadlineInfo['class']) ?>">
                                <?= htmlspecialchars($deadlineInfo['label']) ?>
                            </span>
                            <?php if ($isInvited): ?>
                                <span class="job-pill invited-company">Invited by Company</span>
                            <?php endif; ?>
                        </div>

                        <div class="job-description">
                            <?= nl2br(htmlspecialchars(rjShortText($job['description'] ?? '', 240))) ?>
                        </div>

                        <?php if ($isInvited): ?>
                            <div class="invite-note-modern">
                                <strong>Company invitation</strong>
                                <?= !empty($invitation['message'])
                                    ? htmlspecialchars($invitation['message'])
                                    : 'This company invited you to apply because your profile matches this job.' ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($job['ai_reason']) || !empty($job['missing_skills']) || !empty($job['suggested_action'])): ?>
                            <div class="ai-box-modern">
                                <div class="ai-title">AI Match Insight</div>

                                <?php if (!empty($job['ai_reason'])): ?>
                                    <div class="ai-text"><?= htmlspecialchars($job['ai_reason']) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($job['missing_skills']) || !empty($job['suggested_action'])): ?>
                                    <div class="ai-grid">
                                        <?php if (!empty($job['missing_skills'])): ?>
                                            <div class="ai-mini">
                                                <strong>Skills to improve</strong>
                                                <span><?= htmlspecialchars($job['missing_skills']) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($job['suggested_action'])): ?>
                                            <div class="ai-mini">
                                                <strong>Suggested action</strong>
                                                <span><?= htmlspecialchars($job['suggested_action']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="job-actions-modern">
                            <div class="job-actions-left">
                                Deadline: <?= htmlspecialchars($deadlineInfo['date']) ?>
                                <?php if ($isInvited && !$isApplied): ?>
                                    • Company invited you to apply<?= $inviteStatus ? ' (' . htmlspecialchars($inviteStatus) . ')' : '' ?>
                                <?php endif; ?>
                                <?php if ($isApplied): ?>
                                    • Application already submitted
                                <?php endif; ?>
                            </div>

                            <div class="job-actions-right">
                                <a href="view_job.php?id=<?= $jobId ?>" class="jobs-btn-light" style="border-color:#eadada;">View Details</a>

                                <?php if ($isApplied): ?>
                                    <a href="applications.php" class="jobs-btn-primary">Already Applied</a>
                                <?php elseif ($isInvited): ?>
                                    <a href="apply_job.php?id=<?= $jobId ?>" class="jobs-btn-primary">Apply from Invite</a>
                                <?php else: ?>
                                    <a href="apply_job.php?id=<?= $jobId ?>" class="jobs-btn-primary">Apply Now</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>

                <div class="jobs-empty-state" id="jobsNoResult" style="display:none;">
                    <div class="jobs-empty-icon">⌕</div>
                    <h3>No matching jobs found</h3>
                    <p>Try a different search keyword or select another filter.</p>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('jobSearchInput');
        const filterButtons = document.querySelectorAll('.jobs-filter-btn');
        const cards = document.querySelectorAll('.job-card-modern');
        const noResult = document.getElementById('jobsNoResult');
        let currentFilter = 'all';

        function applyFilters() {
            const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
            let visibleCount = 0;

            cards.forEach(card => {
                const searchText = card.dataset.search || '';
                const bucket = card.dataset.bucket || '';
                const applied = card.dataset.applied || '';
                const invited = card.dataset.invited || '';

                const matchesSearch = query === '' || searchText.includes(query);
                let matchesFilter = true;

                if (currentFilter === 'strong') {
                    matchesFilter = bucket === 'strong';
                } else if (currentFilter === 'good') {
                    matchesFilter = bucket === 'good';
                } else if (currentFilter === 'invited') {
                    matchesFilter = invited === 'invited';
                } else if (currentFilter === 'applied') {
                    matchesFilter = applied === 'applied';
                } else if (currentFilter === 'not-applied') {
                    matchesFilter = applied === 'not-applied';
                }

                const shouldShow = matchesSearch && matchesFilter;
                card.style.display = shouldShow ? '' : 'none';

                if (shouldShow) {
                    visibleCount++;
                }
            });

            if (noResult) {
                noResult.style.display = visibleCount === 0 ? '' : 'none';
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }

        filterButtons.forEach(button => {
            button.addEventListener('click', function () {
                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                currentFilter = this.dataset.filter || 'all';
                applyFilters();
            });
        });
    });
</script>

<?php require_once '../includes/dashboard_footer.php'; ?>
