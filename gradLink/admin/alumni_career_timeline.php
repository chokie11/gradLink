<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatDateLong($date)
{
    if (empty($date)) {
        return '—';
    }

    return date('F j, Y • g:i A', strtotime($date));
}

function formatDateShort($date)
{
    if (empty($date)) {
        return '—';
    }

    return date('M j, Y', strtotime($date));
}

function formatMonthYear($date)
{
    if (empty($date)) {
        return '—';
    }

    return date('M Y', strtotime($date));
}

function answerValue(array $answers, array $keywords)
{
    foreach ($answers as $item) {
        $question = strtolower((string) ($item['question_text'] ?? ''));
        foreach ($keywords as $keyword) {
            if (strpos($question, strtolower($keyword)) !== false) {
                return trim((string) ($item['answer_text'] ?? ''));
            }
        }
    }

    return '';
}

function classifyEmployment($answer)
{
    $text = strtolower(trim((string) $answer));

    if ($text === '') {
        return 'unknown';
    }

    if (strpos($text, 'unemployed') !== false) {
        return 'unemployed';
    }

    if (strpos($text, 'under') !== false && strpos($text, 'employ') !== false) {
        return 'underemployed';
    }

    if (strpos($text, 'self') !== false || strpos($text, 'freelance') !== false) {
        return 'self';
    }

    if (strpos($text, 'employed') !== false || strpos($text, 'job') !== false || strpos($text, 'working') !== false) {
        return 'employed';
    }

    return 'other';
}

function employmentLabel($category)
{
    $labels = [
        'employed' => 'Employed',
        'self' => 'Self-employed / Freelance',
        'underemployed' => 'Underemployed',
        'unemployed' => 'Unemployed',
        'other' => 'Other',
        'unknown' => 'No Status'
    ];

    return $labels[$category] ?? 'No Status';
}

function badgeClassForEmployment($category)
{
    $classes = [
        'employed' => 'timeline-status-good',
        'self' => 'timeline-status-info',
        'underemployed' => 'timeline-status-warning',
        'unemployed' => 'timeline-status-danger',
        'other' => 'timeline-status-neutral',
        'unknown' => 'timeline-status-neutral'
    ];

    return $classes[$category] ?? 'timeline-status-neutral';
}

function shortText($value, $limit = 140)
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '...' : $text;
    }

    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

$alumniId = $_GET['id'] ?? ($_GET['alumni_id'] ?? null);

if (!$alumniId || !ctype_digit((string) $alumniId)) {
    header('Location: tracer_dashboard.php');
    exit;
}

$alumniId = (int) $alumniId;

/*
|--------------------------------------------------------------------------
| Load alumni profile
|--------------------------------------------------------------------------
*/
$alumniStmt = $pdo->prepare("\n    SELECT\n        u.id,\n        u.first_name,\n        u.last_name,\n        u.email,\n        u.status AS account_status,\n        ap.student_id,\n        ap.course,\n        ap.major,\n        ap.graduation_year,\n        ap.phone,\n        ap.address,\n        ap.employment_status AS profile_employment_status,\n        ap.skills,\n        ap.preferred_job_titles,\n        ap.preferred_location\n    FROM users u\n    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id\n    WHERE u.id = ?\n      AND u.role = 'alumni'\n    LIMIT 1\n");
$alumniStmt->execute([$alumniId]);
$alumni = $alumniStmt->fetch(PDO::FETCH_ASSOC);

if (!$alumni) {
    $pageTitle = 'Alumni Timeline Not Found';
    $pageSubtitle = 'The selected alumni account could not be found.';
    $activePage = 'tracer_dashboard';
    require_once '../includes/dashboard_header.php';
    ?>
    <div class="panel-card">
        <div class="panel-title">Alumni not found</div>
        <p class="text-muted mb-4">The selected alumni account may have been deleted or is not an alumni user.</p>
        <a href="tracer_dashboard.php" class="btn-maroon">Back to Tracer Dashboard</a>
    </div>
    <?php
    require_once '../includes/dashboard_footer.php';
    exit;
}

/*
|--------------------------------------------------------------------------
| Load all tracer responses for this alumni
|--------------------------------------------------------------------------
*/
$responsesStmt = $pdo->prepare("\n    SELECT\n        tr.id AS response_id,\n        tr.survey_id,\n        tr.submitted_at,\n        tr.survey_version,\n        tr.is_latest,\n        ts.title AS survey_title,\n        ts.description AS survey_description,\n        ts.version AS current_survey_version,\n        ts.is_active AS survey_is_active\n    FROM tracer_responses tr\n    INNER JOIN tracer_surveys ts ON tr.survey_id = ts.id\n    WHERE tr.alumni_id = ?\n      AND ts.survey_type = 'tracer'\n    ORDER BY tr.submitted_at DESC, tr.id DESC\n");
$responsesStmt->execute([$alumniId]);
$responses = $responsesStmt->fetchAll(PDO::FETCH_ASSOC);

$answerMap = [];

if (!empty($responses)) {
    $responseIds = array_column($responses, 'response_id');
    $placeholders = implode(',', array_fill(0, count($responseIds), '?'));

    $answersStmt = $pdo->prepare("\n        SELECT\n            ta.response_id,\n            tq.question_text,\n            tq.question_type,\n            tq.sort_order,\n            ta.answer_text\n        FROM tracer_answers ta\n        INNER JOIN tracer_questions tq ON ta.question_id = tq.id\n        WHERE ta.response_id IN ($placeholders)\n        ORDER BY ta.response_id ASC, tq.sort_order ASC, tq.id ASC\n    ");
    $answersStmt->execute($responseIds);
    $answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($answers as $answer) {
        $answerMap[$answer['response_id']][] = $answer;
    }
}

$timelineItems = [];
$employmentSequence = [];
$sequenceByResponseId = [];

$ascendingResponses = array_reverse($responses);
foreach ($ascendingResponses as $index => $response) {
    $sequenceByResponseId[(int) $response['response_id']] = $index + 1;
}

foreach ($responses as $response) {
    $answers = $answerMap[$response['response_id']] ?? [];
    $employmentStatus = answerValue($answers, ['employment status', 'status of employment']);
    $employmentCategory = classifyEmployment($employmentStatus);
    $employerName = answerValue($answers, ['name of your employer', 'employer name', 'employer']);
    $jobRole = answerValue($answers, ['job description', 'job role', 'job title', 'role']);
    $sector = answerValue($answers, ['sector']);
    $workLocation = answerValue($answers, ['work location', 'where is your work', 'location']);
    $furtherStudies = answerValue($answers, ['further studies', 'pursuing further']);
    $degreePursuing = answerValue($answers, ['degree currently pursuing', 'degree pursuing', 'degree']);
    $skills = answerValue($answers, ['skills']);
    $challenges = answerValue($answers, ['challenges']);
    $learningExperience = answerValue($answers, ['overall learning experience', 'learning experience']);

    $timelineItems[] = [
        'response_id' => (int) $response['response_id'],
        'survey_id' => (int) $response['survey_id'],
        'survey_title' => $response['survey_title'] ?? 'Tracer Survey',
        'survey_version' => $response['survey_version'] ?? 1,
        'current_survey_version' => $response['current_survey_version'] ?? 1,
        'survey_is_active' => (int) ($response['survey_is_active'] ?? 0),
        'submitted_at' => $response['submitted_at'],
        'is_latest' => (int) ($response['is_latest'] ?? 0),
        'sequence' => $sequenceByResponseId[(int) $response['response_id']] ?? 1,
        'answers' => $answers,
        'employment_status' => $employmentStatus,
        'employment_category' => $employmentCategory,
        'employer_name' => $employerName,
        'job_role' => $jobRole,
        'sector' => $sector,
        'work_location' => $workLocation,
        'further_studies' => $furtherStudies,
        'degree_pursuing' => $degreePursuing,
        'skills' => $skills,
        'challenges' => $challenges,
        'learning_experience' => $learningExperience,
    ];

    $employmentSequence[] = $employmentStatus !== '' ? $employmentStatus : employmentLabel($employmentCategory);
}

$totalUpdates = count($timelineItems);
$latestItem = $timelineItems[0] ?? null;
$oldestItem = !empty($timelineItems) ? $timelineItems[count($timelineItems) - 1] : null;
$currentStatus = $latestItem['employment_status'] ?? '';
$currentCategory = $latestItem ? $latestItem['employment_category'] : 'unknown';
$currentEmployer = $latestItem['employer_name'] ?? '';
$currentRole = $latestItem['job_role'] ?? '';
$currentLocation = $latestItem['work_location'] ?? '';

$fullName = trim(($alumni['first_name'] ?? '') . ' ' . ($alumni['last_name'] ?? ''));

$pageTitle = 'Alumni Career Timeline';
$pageSubtitle = 'Review tracer submissions and career progression history.';
$activePage = 'tracer_dashboard';

require_once '../includes/dashboard_header.php';
?>

<style>
    .career-timeline-page {
        --timeline-maroon: #800000;
        --timeline-dark-maroon: #5f0000;
        --timeline-soft-maroon: #fff3f3;
        --timeline-border: #eadada;
        --timeline-text: #231f20;
        --timeline-muted: #776b6b;
        --timeline-gold: #f7c948;
    }

    .timeline-hero {
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.2), transparent 34%),
            linear-gradient(135deg, var(--timeline-maroon), var(--timeline-dark-maroon));
        color: #fff;
        border-radius: 28px;
        padding: 30px;
        box-shadow: 0 22px 45px rgba(128, 0, 0, 0.18);
        margin-bottom: 24px;
        position: relative;
        overflow: hidden;
    }

    .timeline-hero::after {
        content: '';
        position: absolute;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.08);
        right: -70px;
        bottom: -90px;
    }

    .timeline-hero-content {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: 1.4fr 0.8fr;
        gap: 22px;
        align-items: stretch;
    }

    .timeline-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.24);
        padding: 8px 13px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.7px;
        margin-bottom: 14px;
    }

    .timeline-title {
        font-size: clamp(28px, 4vw, 42px);
        font-weight: 900;
        letter-spacing: -1.2px;
        margin-bottom: 10px;
        line-height: 1.05;
    }

    .timeline-subtitle {
        max-width: 760px;
        color: rgba(255, 255, 255, 0.86);
        font-size: 15px;
        line-height: 1.7;
        margin-bottom: 20px;
    }

    .timeline-hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .timeline-btn-light,
    .timeline-btn-outline,
    .timeline-btn-maroon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 999px;
        padding: 11px 17px;
        font-weight: 800;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        cursor: pointer;
    }

    .timeline-btn-light {
        background: #fff;
        color: var(--timeline-maroon);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.12);
    }

    .timeline-btn-light:hover,
    .timeline-btn-maroon:hover {
        transform: translateY(-1px);
        color: var(--timeline-maroon);
    }

    .timeline-btn-outline {
        background: rgba(255, 255, 255, 0.08);
        color: #fff;
        border-color: rgba(255, 255, 255, 0.28);
    }

    .timeline-btn-outline:hover {
        background: rgba(255, 255, 255, 0.16);
        color: #fff;
        transform: translateY(-1px);
    }

    .timeline-btn-maroon {
        background: var(--timeline-maroon);
        color: #fff;
        box-shadow: 0 12px 24px rgba(128, 0, 0, 0.18);
    }

    .timeline-btn-maroon:hover {
        background: var(--timeline-dark-maroon);
        color: #fff;
    }

    .timeline-profile-card {
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 24px;
        padding: 18px;
        backdrop-filter: blur(8px);
    }

    .timeline-avatar {
        width: 62px;
        height: 62px;
        border-radius: 20px;
        background: #fff;
        color: var(--timeline-maroon);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        font-weight: 900;
        margin-bottom: 12px;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
    }

    .timeline-profile-name {
        font-size: 20px;
        font-weight: 900;
        margin-bottom: 4px;
    }

    .timeline-profile-meta {
        color: rgba(255, 255, 255, 0.82);
        font-size: 13px;
        line-height: 1.6;
    }

    .timeline-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .timeline-stat-card {
        background: #fff;
        border: 1px solid var(--timeline-border);
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 16px 35px rgba(128, 0, 0, 0.06);
    }

    .timeline-stat-label {
        color: var(--timeline-muted);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.7px;
        margin-bottom: 8px;
    }

    .timeline-stat-value {
        color: var(--timeline-text);
        font-size: 26px;
        font-weight: 900;
        letter-spacing: -0.8px;
        margin-bottom: 4px;
    }

    .timeline-stat-note {
        color: var(--timeline-muted);
        font-size: 13px;
    }

    .timeline-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) 360px;
        gap: 22px;
        align-items: start;
    }

    .timeline-panel {
        background: #fff;
        border: 1px solid var(--timeline-border);
        border-radius: 24px;
        box-shadow: 0 16px 35px rgba(128, 0, 0, 0.06);
        overflow: hidden;
    }

    .timeline-panel-header {
        padding: 20px 22px;
        border-bottom: 1px solid #f1e4e4;
        display: flex;
        justify-content: space-between;
        gap: 14px;
        align-items: center;
    }

    .timeline-panel-title {
        color: var(--timeline-text);
        font-size: 18px;
        font-weight: 900;
        margin-bottom: 4px;
    }

    .timeline-panel-subtitle {
        color: var(--timeline-muted);
        font-size: 13px;
    }

    .timeline-stack {
        position: relative;
        padding: 22px;
    }

    .timeline-stack::before {
        content: '';
        position: absolute;
        left: 41px;
        top: 30px;
        bottom: 30px;
        width: 3px;
        border-radius: 99px;
        background: linear-gradient(180deg, rgba(128, 0, 0, 0.42), rgba(128, 0, 0, 0.06));
    }

    .timeline-item {
        position: relative;
        display: grid;
        grid-template-columns: 44px minmax(0, 1fr);
        gap: 18px;
        margin-bottom: 18px;
    }

    .timeline-item:last-child {
        margin-bottom: 0;
    }

    .timeline-dot {
        width: 42px;
        height: 42px;
        border-radius: 16px;
        background: #fff;
        border: 3px solid var(--timeline-maroon);
        color: var(--timeline-maroon);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        position: relative;
        z-index: 1;
        box-shadow: 0 8px 18px rgba(128, 0, 0, 0.12);
    }

    .timeline-dot.current {
        background: var(--timeline-maroon);
        color: #fff;
    }

    .timeline-card {
        background: #fff;
        border: 1px solid var(--timeline-border);
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 12px 26px rgba(128, 0, 0, 0.05);
    }

    .timeline-card-top {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 14px;
    }

    .timeline-card-date {
        color: var(--timeline-maroon);
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.7px;
        margin-bottom: 5px;
    }

    .timeline-card-title {
        color: var(--timeline-text);
        font-size: 18px;
        font-weight: 900;
        line-height: 1.25;
        margin-bottom: 4px;
    }

    .timeline-card-subtitle {
        color: var(--timeline-muted);
        font-size: 13px;
        line-height: 1.5;
    }

    .timeline-badge,
    .timeline-mini-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
    }

    .timeline-mini-badge {
        padding: 6px 9px;
        font-size: 11px;
        background: #f8eeee;
        color: var(--timeline-maroon);
    }

    .timeline-status-good {
        background: #eaf8ef;
        color: #127235;
    }

    .timeline-status-info {
        background: #eef6ff;
        color: #1d5f99;
    }

    .timeline-status-warning {
        background: #fff7e6;
        color: #9a6200;
    }

    .timeline-status-danger {
        background: #fff0f0;
        color: #b32424;
    }

    .timeline-status-neutral {
        background: #f3f3f3;
        color: #565656;
    }

    .timeline-detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 14px;
    }

    .timeline-detail {
        background: #fffafa;
        border: 1px solid #f1e3e3;
        border-radius: 16px;
        padding: 12px;
    }

    .timeline-detail-label {
        color: var(--timeline-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.7px;
        margin-bottom: 5px;
    }

    .timeline-detail-value {
        color: var(--timeline-text);
        font-size: 14px;
        font-weight: 800;
        word-break: break-word;
    }

    .timeline-card-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        padding-top: 12px;
        border-top: 1px solid #f1e3e3;
    }

    .timeline-action-link {
        color: var(--timeline-maroon);
        font-weight: 900;
        text-decoration: none;
        font-size: 13px;
    }

    .timeline-action-link:hover {
        color: var(--timeline-dark-maroon);
        text-decoration: underline;
    }

    .timeline-side-stack {
        display: grid;
        gap: 16px;
    }

    .timeline-current-card {
        background: #fff;
        border: 1px solid var(--timeline-border);
        border-radius: 24px;
        padding: 20px;
        box-shadow: 0 16px 35px rgba(128, 0, 0, 0.06);
    }

    .timeline-current-status {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }

    .timeline-current-title {
        color: var(--timeline-text);
        font-size: 18px;
        font-weight: 900;
        margin-bottom: 4px;
    }

    .timeline-current-subtitle {
        color: var(--timeline-muted);
        font-size: 13px;
        line-height: 1.5;
    }

    .timeline-info-list {
        display: grid;
        gap: 10px;
    }

    .timeline-info-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 11px 0;
        border-bottom: 1px solid #f2e6e6;
    }

    .timeline-info-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .timeline-info-key {
        color: var(--timeline-muted);
        font-size: 13px;
        font-weight: 700;
    }

    .timeline-info-value {
        color: var(--timeline-text);
        font-size: 13px;
        font-weight: 900;
        text-align: right;
        max-width: 58%;
        word-break: break-word;
    }

    .timeline-empty {
        background: #fff;
        border: 1px dashed #e7cfcf;
        border-radius: 24px;
        padding: 34px;
        text-align: center;
        color: var(--timeline-muted);
    }

    .timeline-empty-icon {
        width: 62px;
        height: 62px;
        border-radius: 20px;
        background: var(--timeline-soft-maroon);
        color: var(--timeline-maroon);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        font-weight: 900;
        margin-bottom: 14px;
    }

    .timeline-mini-note {
        color: var(--timeline-muted);
        font-size: 13px;
        line-height: 1.6;
    }

    @media print {
        .sidebar,
        .topbar,
        .timeline-hero-actions,
        .timeline-card-actions,
        .timeline-btn-maroon,
        .timeline-btn-light,
        .timeline-btn-outline {
            display: none !important;
        }

        .main-panel {
            margin-left: 0 !important;
        }

        .content-area {
            padding: 0 !important;
        }

        .timeline-hero,
        .timeline-panel,
        .timeline-current-card,
        .timeline-stat-card,
        .timeline-card {
            box-shadow: none !important;
        }
    }

    @media (max-width: 1100px) {
        .timeline-hero-content,
        .timeline-grid,
        .timeline-stat-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 720px) {
        .timeline-hero {
            padding: 22px;
            border-radius: 22px;
        }

        .timeline-detail-grid {
            grid-template-columns: 1fr;
        }

        .timeline-stack {
            padding: 16px;
        }

        .timeline-stack::before {
            left: 34px;
        }

        .timeline-item {
            grid-template-columns: 36px minmax(0, 1fr);
            gap: 13px;
        }

        .timeline-dot {
            width: 36px;
            height: 36px;
            border-radius: 13px;
            font-size: 13px;
        }

        .timeline-card-top,
        .timeline-current-status,
        .timeline-info-row {
            flex-direction: column;
            align-items: flex-start;
        }

        .timeline-info-value {
            text-align: left;
            max-width: 100%;
        }
    }
</style>

<div class="career-timeline-page">
    <div class="timeline-hero">
        <div class="timeline-hero-content">
            <div>
                <div class="timeline-kicker">Career Timeline</div>
                <div class="timeline-title"><?= h($fullName ?: 'Unnamed Alumni') ?></div>
                <div class="timeline-subtitle">
                    Review this alumni's tracer study submissions and career progression over time. The latest record is used as the current career status in the tracer dashboard.
                </div>
                <div class="timeline-hero-actions">
                    <a href="tracer_dashboard.php" class="timeline-btn-light">Back to Tracer Dashboard</a>
                    <?php if ($latestItem): ?>
                        <a href="view_tracer_response.php?id=<?= (int) $latestItem['response_id'] ?>" class="timeline-btn-outline">View Latest Response</a>
                    <?php endif; ?>
                    <button type="button" onclick="window.print()" class="timeline-btn-outline">Print Timeline</button>
                </div>
            </div>

            <div class="timeline-profile-card">
                <div class="timeline-avatar">
                    <?= h(strtoupper(substr($alumni['first_name'] ?? 'A', 0, 1) . substr($alumni['last_name'] ?? '', 0, 1))) ?>
                </div>
                <div class="timeline-profile-name"><?= h($fullName ?: 'Unnamed Alumni') ?></div>
                <div class="timeline-profile-meta">
                    <?= h($alumni['email'] ?? 'No email') ?><br>
                    <?= h($alumni['course'] ?: 'No course') ?><?= !empty($alumni['major']) ? ' • ' . h($alumni['major']) : '' ?><br>
                    <?= !empty($alumni['graduation_year']) ? 'Batch ' . h($alumni['graduation_year']) : 'No graduation year' ?>
                </div>
            </div>
        </div>
    </div>

    <div class="timeline-stat-grid">
        <div class="timeline-stat-card">
            <div class="timeline-stat-label">Career Updates</div>
            <div class="timeline-stat-value"><?= (int) $totalUpdates ?></div>
            <div class="timeline-stat-note">Tracer submissions recorded</div>
        </div>

        <div class="timeline-stat-card">
            <div class="timeline-stat-label">Current Status</div>
            <div class="timeline-stat-value" style="font-size: 20px;"><?= h($currentStatus ?: employmentLabel($currentCategory)) ?></div>
            <div class="timeline-stat-note"><?= $latestItem ? 'Based on latest response' : 'No tracer response yet' ?></div>
        </div>

        <div class="timeline-stat-card">
            <div class="timeline-stat-label">First Record</div>
            <div class="timeline-stat-value" style="font-size: 20px;"><?= h($oldestItem ? formatMonthYear($oldestItem['submitted_at']) : '—') ?></div>
            <div class="timeline-stat-note">Initial tracer submission</div>
        </div>

        <div class="timeline-stat-card">
            <div class="timeline-stat-label">Latest Update</div>
            <div class="timeline-stat-value" style="font-size: 20px;"><?= h($latestItem ? formatMonthYear($latestItem['submitted_at']) : '—') ?></div>
            <div class="timeline-stat-note">Most recent career snapshot</div>
        </div>
    </div>

    <div class="timeline-grid">
        <div class="timeline-panel">
            <div class="timeline-panel-header">
                <div>
                    <div class="timeline-panel-title">Career Progression History</div>
                    <div class="timeline-panel-subtitle">Every submitted tracer update is preserved as a historical career snapshot.</div>
                </div>
            </div>

            <?php if (empty($timelineItems)): ?>
                <div class="timeline-empty m-4">
                    <div class="timeline-empty-icon">⌛</div>
                    <h5 class="fw-bold mb-2">No tracer timeline yet</h5>
                    <div>This alumni has not submitted the tracer survey or career update form yet.</div>
                </div>
            <?php else: ?>
                <div class="timeline-stack">
                    <?php foreach ($timelineItems as $item): ?>
                        <?php
                        $statusText = $item['employment_status'] ?: employmentLabel($item['employment_category']);
                        $mainTitle = $item['job_role'] ?: $statusText;
                        if ($item['employer_name'] !== '') {
                            $mainTitle .= ' at ' . $item['employer_name'];
                        }
                        ?>
                        <div class="timeline-item">
                            <div class="timeline-dot <?= $item['is_latest'] === 1 ? 'current' : '' ?>">
                                <?= (int) $item['sequence'] ?>
                            </div>

                            <div class="timeline-card">
                                <div class="timeline-card-top">
                                    <div>
                                        <div class="timeline-card-date"><?= h(formatDateLong($item['submitted_at'])) ?></div>
                                        <div class="timeline-card-title"><?= h($mainTitle ?: 'Career Update') ?></div>
                                        <div class="timeline-card-subtitle">
                                            <?= h($item['survey_title']) ?> • Version <?= (int) $item['survey_version'] ?>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column gap-2 align-items-start align-items-md-end">
                                        <span class="timeline-badge <?= h(badgeClassForEmployment($item['employment_category'])) ?>">
                                            <?= h($statusText) ?>
                                        </span>
                                        <?php if ($item['is_latest'] === 1): ?>
                                            <span class="timeline-mini-badge">Current</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="timeline-detail-grid">
                                    <div class="timeline-detail">
                                        <div class="timeline-detail-label">Employer</div>
                                        <div class="timeline-detail-value"><?= h($item['employer_name'] ?: 'Not provided') ?></div>
                                    </div>
                                    <div class="timeline-detail">
                                        <div class="timeline-detail-label">Role / Job Description</div>
                                        <div class="timeline-detail-value"><?= h($item['job_role'] ?: 'Not provided') ?></div>
                                    </div>
                                    <div class="timeline-detail">
                                        <div class="timeline-detail-label">Sector</div>
                                        <div class="timeline-detail-value"><?= h($item['sector'] ?: 'Not provided') ?></div>
                                    </div>
                                    <div class="timeline-detail">
                                        <div class="timeline-detail-label">Work Location</div>
                                        <div class="timeline-detail-value"><?= h($item['work_location'] ?: 'Not provided') ?></div>
                                    </div>
                                </div>

                                <?php if (!empty($item['further_studies']) || !empty($item['degree_pursuing']) || !empty($item['skills']) || !empty($item['challenges']) || !empty($item['learning_experience'])): ?>
                                    <div class="timeline-detail-grid">
                                        <?php if (!empty($item['further_studies']) || !empty($item['degree_pursuing'])): ?>
                                            <div class="timeline-detail">
                                                <div class="timeline-detail-label">Further Studies</div>
                                                <div class="timeline-detail-value">
                                                    <?= h($item['further_studies'] ?: 'Not provided') ?>
                                                    <?= !empty($item['degree_pursuing']) ? ' • ' . h($item['degree_pursuing']) : '' ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($item['learning_experience'])): ?>
                                            <div class="timeline-detail">
                                                <div class="timeline-detail-label">Learning Experience</div>
                                                <div class="timeline-detail-value"><?= h($item['learning_experience']) ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($item['skills'])): ?>
                                            <div class="timeline-detail">
                                                <div class="timeline-detail-label">Skills</div>
                                                <div class="timeline-detail-value"><?= h(shortText($item['skills'], 130)) ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($item['challenges'])): ?>
                                            <div class="timeline-detail">
                                                <div class="timeline-detail-label">Challenges</div>
                                                <div class="timeline-detail-value"><?= h(shortText($item['challenges'], 130)) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="timeline-card-actions">
                                    <a href="view_tracer_response.php?id=<?= (int) $item['response_id'] ?>" class="timeline-action-link">View Full Response</a>
                                    <span class="timeline-mini-note">Response #<?= (int) $item['response_id'] ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="timeline-side-stack">
            <div class="timeline-current-card">
                <div class="timeline-current-status">
                    <div>
                        <div class="timeline-current-title">Current Career Status</div>
                        <div class="timeline-current-subtitle">Latest tracer update from this alumni.</div>
                    </div>
                    <span class="timeline-badge <?= h(badgeClassForEmployment($currentCategory)) ?>">
                        <?= h($currentStatus ?: employmentLabel($currentCategory)) ?>
                    </span>
                </div>

                <div class="timeline-info-list">
                    <div class="timeline-info-row">
                        <div class="timeline-info-key">Employer</div>
                        <div class="timeline-info-value"><?= h($currentEmployer ?: '—') ?></div>
                    </div>
                    <div class="timeline-info-row">
                        <div class="timeline-info-key">Role</div>
                        <div class="timeline-info-value"><?= h($currentRole ?: '—') ?></div>
                    </div>
                    <div class="timeline-info-row">
                        <div class="timeline-info-key">Location</div>
                        <div class="timeline-info-value"><?= h($currentLocation ?: '—') ?></div>
                    </div>
                    <div class="timeline-info-row">
                        <div class="timeline-info-key">Last Updated</div>
                        <div class="timeline-info-value"><?= h($latestItem ? formatDateShort($latestItem['submitted_at']) : '—') ?></div>
                    </div>
                </div>
            </div>

            <div class="timeline-current-card">
                <div class="timeline-current-title">Alumni Profile</div>
                <div class="timeline-current-subtitle mb-3">Academic and contact details from the alumni profile.</div>

                <div class="timeline-info-list">
                    <div class="timeline-info-row">
                        <div class="timeline-info-key">Student ID</div>
                        <div class="timeline-info-value"><?= h($alumni['student_id'] ?: '—') ?></div>
                    </div>
                    <div class="timeline-info-row">
                        <div class="timeline-info-key">Course</div>
                        <div class="timeline-info-value"><?= h($alumni['course'] ?: '—') ?></div>
                    </div>
                    <div class="timeline-info-row">
                        <div class="timeline-info-key">Major</div>
                        <div class="timeline-info-value"><?= h($alumni['major'] ?: '—') ?></div>
                    </div>
                    <div class="timeline-info-row">
                        <div class="timeline-info-key">Batch</div>
                        <div class="timeline-info-value"><?= h($alumni['graduation_year'] ?: '—') ?></div>
                    </div>
                    <div class="timeline-info-row">
                        <div class="timeline-info-key">Phone</div>
                        <div class="timeline-info-value"><?= h($alumni['phone'] ?: '—') ?></div>
                    </div>
                    <div class="timeline-info-row">
                        <div class="timeline-info-key">Preferred Location</div>
                        <div class="timeline-info-value"><?= h($alumni['preferred_location'] ?: '—') ?></div>
                    </div>
                </div>
            </div>

            <div class="timeline-current-card">
                <div class="timeline-current-title">Admin Note</div>
                <div class="timeline-mini-note mt-2">
                    This timeline shows all tracer-study career snapshots for one alumni. The newest submission is treated as the current career status, while older submissions remain available for history and reporting.
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
