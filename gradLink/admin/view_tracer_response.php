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
        'employed' => 'response-status-good',
        'self' => 'response-status-info',
        'underemployed' => 'response-status-warning',
        'unemployed' => 'response-status-danger',
        'other' => 'response-status-neutral',
        'unknown' => 'response-status-neutral'
    ];

    return $classes[$category] ?? 'response-status-neutral';
}

$responseId = $_GET['id'] ?? ($_GET['response_id'] ?? null);

if (!$responseId || !ctype_digit((string) $responseId)) {
    header('Location: tracer_dashboard.php');
    exit;
}

$responseId = (int) $responseId;

/*
|--------------------------------------------------------------------------
| Load tracer response with alumni and survey information
|--------------------------------------------------------------------------
*/
$responseStmt = $pdo->prepare("\n    SELECT\n        tr.id AS response_id,\n        tr.survey_id,\n        tr.alumni_id,\n        tr.submitted_at,\n        tr.survey_version,\n        tr.is_latest,\n        ts.title AS survey_title,\n        ts.description AS survey_description,\n        ts.survey_type,\n        ts.is_active AS survey_is_active,\n        ts.version AS current_survey_version,\n        u.first_name,\n        u.last_name,\n        u.email,\n        u.status AS account_status,\n        ap.student_id,\n        ap.course,\n        ap.major,\n        ap.graduation_year,\n        ap.phone,\n        ap.address,\n        ap.employment_status AS profile_employment_status\n    FROM tracer_responses tr\n    INNER JOIN tracer_surveys ts ON tr.survey_id = ts.id\n    INNER JOIN users u ON tr.alumni_id = u.id\n    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id\n    WHERE tr.id = ?\n      AND ts.survey_type = 'tracer'\n    LIMIT 1\n");
$responseStmt->execute([$responseId]);
$response = $responseStmt->fetch(PDO::FETCH_ASSOC);

if (!$response) {
    $pageTitle = 'Tracer Response Not Found';
    $pageSubtitle = 'The selected tracer response could not be found.';
    $activePage = 'tracer_dashboard';
    require_once '../includes/dashboard_header.php';
    ?>
    <div class="panel-card">
        <div class="panel-title">Tracer response not found</div>
        <p class="text-muted mb-4">The response may have been deleted or it may not belong to a tracer study survey.</p>
        <a href="tracer_dashboard.php" class="btn-maroon">Back to Tracer Dashboard</a>
    </div>
    <?php
    require_once '../includes/dashboard_footer.php';
    exit;
}

/*
|--------------------------------------------------------------------------
| Load answers in question order
|--------------------------------------------------------------------------
*/
$answersStmt = $pdo->prepare("\n    SELECT\n        tq.id AS question_id,\n        tq.question_text,\n        tq.question_type,\n        tq.is_required,\n        tq.sort_order,\n        ta.answer_text\n    FROM tracer_questions tq\n    LEFT JOIN tracer_answers ta\n        ON ta.question_id = tq.id\n       AND ta.response_id = ?\n    WHERE tq.survey_id = ?\n    ORDER BY tq.sort_order ASC, tq.id ASC\n");
$answersStmt->execute([$responseId, $response['survey_id']]);
$answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

$answeredCount = 0;
$requiredCount = 0;
$requiredAnsweredCount = 0;

foreach ($answers as $answer) {
    $answerText = trim((string) ($answer['answer_text'] ?? ''));
    if ((int) ($answer['is_required'] ?? 0) === 1) {
        $requiredCount++;
        if ($answerText !== '') {
            $requiredAnsweredCount++;
        }
    }

    if ($answerText !== '') {
        $answeredCount++;
    }
}

$employmentStatus = answerValue($answers, ['employment status', 'status of employment']);
$employmentCategory = classifyEmployment($employmentStatus);
$employerName = answerValue($answers, ['name of your employer', 'employer name', 'employer']);
$jobRole = answerValue($answers, ['job description', 'job role', 'job title', 'role']);
$sector = answerValue($answers, ['sector']);
$workLocation = answerValue($answers, ['work location', 'where is your work', 'location']);
$furtherStudies = answerValue($answers, ['further studies', 'pursuing further']);
$skills = answerValue($answers, ['skills']);
$challenges = answerValue($answers, ['challenges']);

$fullName = trim(($response['first_name'] ?? '') . ' ' . ($response['last_name'] ?? ''));

/*
|--------------------------------------------------------------------------
| Previous / next responses for the same alumni and same tracer survey
|--------------------------------------------------------------------------
*/
$previousStmt = $pdo->prepare("\n    SELECT id, submitted_at\n    FROM tracer_responses\n    WHERE survey_id = ?\n      AND alumni_id = ?\n      AND id < ?\n    ORDER BY id DESC\n    LIMIT 1\n");
$previousStmt->execute([$response['survey_id'], $response['alumni_id'], $responseId]);
$previousResponse = $previousStmt->fetch(PDO::FETCH_ASSOC);

$nextStmt = $pdo->prepare("\n    SELECT id, submitted_at\n    FROM tracer_responses\n    WHERE survey_id = ?\n      AND alumni_id = ?\n      AND id > ?\n    ORDER BY id ASC\n    LIMIT 1\n");
$nextStmt->execute([$response['survey_id'], $response['alumni_id'], $responseId]);
$nextResponse = $nextStmt->fetch(PDO::FETCH_ASSOC);

$historyCountStmt = $pdo->prepare("\n    SELECT COUNT(*)\n    FROM tracer_responses\n    WHERE survey_id = ?\n      AND alumni_id = ?\n");
$historyCountStmt->execute([$response['survey_id'], $response['alumni_id']]);
$historyCount = (int) $historyCountStmt->fetchColumn();

$pageTitle = 'Tracer Response Details';
$pageSubtitle = 'Review one complete alumni tracer study submission.';
$activePage = 'tracer_dashboard';

require_once '../includes/dashboard_header.php';
?>

<style>
    .response-page {
        --response-maroon: #800000;
        --response-dark-maroon: #5f0000;
        --response-soft-maroon: #fff3f3;
        --response-border: #eadada;
        --response-text: #231f20;
        --response-muted: #776b6b;
    }

    .response-hero {
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.2), transparent 34%),
            linear-gradient(135deg, var(--response-maroon), var(--response-dark-maroon));
        color: #fff;
        border-radius: 28px;
        padding: 30px;
        box-shadow: 0 22px 45px rgba(128, 0, 0, 0.18);
        margin-bottom: 24px;
        position: relative;
        overflow: hidden;
    }

    .response-hero::after {
        content: "";
        position: absolute;
        right: -70px;
        bottom: -90px;
        width: 240px;
        height: 240px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.08);
    }

    .response-hero-grid {
        display: grid;
        grid-template-columns: 1.5fr 0.75fr;
        gap: 22px;
        position: relative;
        z-index: 1;
        align-items: stretch;
    }

    .response-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.22);
        padding: 8px 13px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 800;
    }

    .response-title {
        font-size: clamp(28px, 4vw, 42px);
        line-height: 1.05;
        letter-spacing: -0.9px;
        font-weight: 900;
        margin-top: 16px;
        max-width: 820px;
    }

    .response-subtitle {
        color: rgba(255, 255, 255, 0.84);
        margin-top: 12px;
        max-width: 760px;
        line-height: 1.7;
        font-size: 15px;
    }

    .response-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 22px;
    }

    .response-btn-light,
    .response-btn-outline,
    .response-btn-maroon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 999px;
        padding: 10px 16px;
        font-weight: 900;
        text-decoration: none;
        border: 1px solid transparent;
        transition: all 0.2s ease;
        font-size: 14px;
    }

    .response-btn-light {
        background: #fff;
        color: var(--response-maroon);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.14);
    }

    .response-btn-light:hover {
        color: var(--response-dark-maroon);
        transform: translateY(-1px);
    }

    .response-btn-outline {
        color: #fff;
        border-color: rgba(255, 255, 255, 0.36);
        background: rgba(255, 255, 255, 0.08);
    }

    .response-btn-outline:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.16);
        transform: translateY(-1px);
    }

    .response-btn-maroon {
        background: var(--response-maroon);
        color: #fff;
        border-color: var(--response-maroon);
    }

    .response-btn-maroon:hover {
        background: var(--response-dark-maroon);
        color: #fff;
        transform: translateY(-1px);
    }

    .response-hero-panel {
        background: rgba(255, 255, 255, 0.13);
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 24px;
        padding: 22px;
        backdrop-filter: blur(10px);
    }

    .hero-panel-label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: rgba(255, 255, 255, 0.75);
        font-weight: 900;
    }

    .hero-panel-value {
        font-size: 36px;
        line-height: 1.1;
        font-weight: 900;
        margin-top: 12px;
    }

    .hero-panel-note {
        color: rgba(255, 255, 255, 0.8);
        margin-top: 10px;
        line-height: 1.5;
        font-size: 14px;
    }

    .response-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .response-stat {
        background: #fff;
        border: 1px solid var(--response-border);
        border-radius: 22px;
        padding: 20px;
        box-shadow: 0 12px 28px rgba(52, 20, 20, 0.06);
        overflow: hidden;
        position: relative;
    }

    .response-stat::after {
        content: "";
        position: absolute;
        right: -35px;
        top: -45px;
        width: 110px;
        height: 110px;
        border-radius: 50%;
        background: var(--response-soft-maroon);
    }

    .response-stat-label {
        color: var(--response-muted);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.9px;
        font-weight: 900;
        position: relative;
        z-index: 1;
    }

    .response-stat-value {
        color: var(--response-text);
        font-size: 24px;
        font-weight: 900;
        margin-top: 8px;
        position: relative;
        z-index: 1;
        line-height: 1.25;
    }

    .response-stat-note {
        color: var(--response-muted);
        font-size: 13px;
        margin-top: 4px;
        position: relative;
        z-index: 1;
    }

    .response-layout {
        display: grid;
        grid-template-columns: 0.9fr 1.35fr;
        gap: 22px;
        align-items: start;
    }

    .response-panel {
        background: #fff;
        border: 1px solid var(--response-border);
        border-radius: 24px;
        padding: 22px;
        box-shadow: 0 14px 34px rgba(52, 20, 20, 0.06);
        margin-bottom: 22px;
    }

    .response-panel-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--response-text);
        margin-bottom: 4px;
    }

    .response-panel-subtitle {
        color: var(--response-muted);
        font-size: 13px;
        margin-bottom: 18px;
    }

    .response-info-list {
        display: grid;
        gap: 12px;
    }

    .response-info-item {
        border: 1px solid #f1e3e3;
        background: #fffafa;
        border-radius: 16px;
        padding: 14px;
    }

    .response-info-key {
        color: var(--response-muted);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        font-weight: 900;
        margin-bottom: 5px;
    }

    .response-info-value {
        color: var(--response-text);
        font-weight: 800;
        line-height: 1.5;
        word-break: break-word;
    }

    .response-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 7px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
    }

    .response-status-good {
        color: #17633a;
        background: #e8f7ef;
    }

    .response-status-info {
        color: #0c5c6d;
        background: #e8f8fb;
    }

    .response-status-warning {
        color: #8a5a00;
        background: #fff6df;
    }

    .response-status-danger {
        color: #9b1c1c;
        background: #fff0f0;
    }

    .response-status-neutral {
        color: #5b5555;
        background: #f2eeee;
    }

    .answer-list {
        display: grid;
        gap: 14px;
    }

    .answer-card {
        border: 1px solid #efdfdf;
        background: #fff;
        border-radius: 20px;
        padding: 18px;
        transition: all 0.2s ease;
    }

    .answer-card:hover {
        border-color: #e2c5c5;
        box-shadow: 0 12px 28px rgba(52, 20, 20, 0.055);
        transform: translateY(-1px);
    }

    .answer-card-header {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 12px;
        align-items: start;
        margin-bottom: 12px;
    }

    .answer-number {
        width: 34px;
        height: 34px;
        border-radius: 12px;
        background: var(--response-soft-maroon);
        color: var(--response-maroon);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        font-size: 13px;
        flex: 0 0 34px;
    }

    .answer-question {
        color: var(--response-text);
        font-weight: 900;
        line-height: 1.45;
    }

    .answer-type {
        color: var(--response-muted);
        border: 1px solid #ecdada;
        background: #fffafa;
        border-radius: 999px;
        padding: 6px 9px;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .answer-body {
        margin-left: 46px;
        background: #fbf8f8;
        border: 1px solid #f0e7e7;
        border-radius: 16px;
        padding: 14px;
        color: #302929;
        line-height: 1.65;
        word-break: break-word;
        white-space: normal;
    }

    .answer-empty {
        color: #998989;
        font-style: italic;
    }

    .response-navigation {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .nav-response-card {
        border: 1px solid #ecdada;
        border-radius: 18px;
        padding: 14px;
        text-decoration: none;
        color: var(--response-text);
        background: #fffafa;
        transition: all 0.2s ease;
    }

    .nav-response-card:hover {
        color: var(--response-maroon);
        border-color: #dec0c0;
        transform: translateY(-1px);
    }

    .nav-response-label {
        color: var(--response-muted);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        font-weight: 900;
        margin-bottom: 5px;
    }

    .nav-response-date {
        font-weight: 900;
    }

    .print-only {
        display: none;
    }

    @media (max-width: 1100px) {
        .response-hero-grid,
        .response-stat-grid,
        .response-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .response-hero,
        .response-panel {
            border-radius: 20px;
            padding: 20px;
        }

        .answer-card-header {
            grid-template-columns: auto 1fr;
        }

        .answer-type {
            grid-column: 2;
            justify-self: start;
        }

        .answer-body {
            margin-left: 0;
        }

        .response-navigation {
            grid-template-columns: 1fr;
        }
    }

    @media print {
        .sidebar,
        .topbar,
        .response-actions,
        .response-navigation,
        .mobile-top {
            display: none !important;
        }

        .main-panel {
            margin-left: 0 !important;
        }

        .content-area {
            padding: 0 !important;
        }

        .response-hero,
        .response-panel,
        .response-stat {
            box-shadow: none !important;
        }

        .print-only {
            display: block;
        }
    }
</style>

<div class="response-page">
    <div class="response-hero">
        <div class="response-hero-grid">
            <div>
                <span class="response-kicker">Tracer Response</span>
                <div class="response-title"><?= h($fullName ?: 'Unnamed Alumni') ?></div>
                <div class="response-subtitle">
                    Complete tracer study submission for <strong><?= h($response['survey_title']) ?></strong> submitted on <?= h(formatDateLong($response['submitted_at'])) ?>.
                </div>

                <div class="response-actions">
                    <a href="tracer_dashboard.php" class="response-btn-light">Back to Tracer Dashboard</a>
                    <a href="survey_responses.php?id=<?= (int) $response['survey_id'] ?>" class="response-btn-outline">All Survey Responses</a>
                    <button type="button" onclick="window.print()" class="response-btn-outline">Print Response</button>
                </div>
            </div>

            <div class="response-hero-panel">
                <div class="hero-panel-label">Response Status</div>
                <div class="hero-panel-value"><?= ((int) $response['is_latest'] === 1) ? 'Latest' : 'History' ?></div>
                <div class="hero-panel-note">
                    This alumni has <?= $historyCount ?> tracer career <?= $historyCount === 1 ? 'record' : 'records' ?> for this active survey.
                </div>
            </div>
        </div>
    </div>

    <div class="response-stat-grid">
        <div class="response-stat">
            <div class="response-stat-label">Employment Status</div>
            <div class="response-stat-value">
                <span class="response-badge <?= h(badgeClassForEmployment($employmentCategory)) ?>">
                    <?= h($employmentStatus ?: employmentLabel($employmentCategory)) ?>
                </span>
            </div>
            <div class="response-stat-note">Based on submitted answer</div>
        </div>

        <div class="response-stat">
            <div class="response-stat-label">Employer / Company</div>
            <div class="response-stat-value"><?= h($employerName ?: '—') ?></div>
            <div class="response-stat-note"><?= h($jobRole ?: 'No role provided') ?></div>
        </div>

        <div class="response-stat">
            <div class="response-stat-label">Work Location</div>
            <div class="response-stat-value"><?= h($workLocation ?: '—') ?></div>
            <div class="response-stat-note"><?= h($sector ?: 'No sector provided') ?></div>
        </div>

        <div class="response-stat">
            <div class="response-stat-label">Completion</div>
            <div class="response-stat-value"><?= $answeredCount ?> / <?= count($answers) ?></div>
            <div class="response-stat-note"><?= $requiredAnsweredCount ?> of <?= $requiredCount ?> required answered</div>
        </div>
    </div>

    <div class="response-layout">
        <div>
            <div class="response-panel">
                <div class="response-panel-title">Alumni Information</div>
                <div class="response-panel-subtitle">Basic alumni profile connected to this response.</div>

                <div class="response-info-list">
                    <div class="response-info-item">
                        <div class="response-info-key">Full Name</div>
                        <div class="response-info-value"><?= h($fullName ?: '—') ?></div>
                    </div>

                    <div class="response-info-item">
                        <div class="response-info-key">Email</div>
                        <div class="response-info-value"><?= h($response['email'] ?? '—') ?></div>
                    </div>

                    <div class="response-info-item">
                        <div class="response-info-key">Student ID</div>
                        <div class="response-info-value"><?= h($response['student_id'] ?? '—') ?></div>
                    </div>

                    <div class="response-info-item">
                        <div class="response-info-key">Course / Major</div>
                        <div class="response-info-value">
                            <?= h($response['course'] ?: '—') ?>
                            <?= !empty($response['major']) ? '<br><span class="text-muted">' . h($response['major']) . '</span>' : '' ?>
                        </div>
                    </div>

                    <div class="response-info-item">
                        <div class="response-info-key">Graduation Year</div>
                        <div class="response-info-value"><?= h($response['graduation_year'] ?? '—') ?></div>
                    </div>

                    <div class="response-info-item">
                        <div class="response-info-key">Phone</div>
                        <div class="response-info-value"><?= h($response['phone'] ?? '—') ?></div>
                    </div>
                </div>
            </div>

            <div class="response-panel">
                <div class="response-panel-title">Response Details</div>
                <div class="response-panel-subtitle">Submission metadata and survey version information.</div>

                <div class="response-info-list">
                    <div class="response-info-item">
                        <div class="response-info-key">Survey Title</div>
                        <div class="response-info-value"><?= h($response['survey_title']) ?></div>
                    </div>

                    <div class="response-info-item">
                        <div class="response-info-key">Submitted</div>
                        <div class="response-info-value"><?= h(formatDateLong($response['submitted_at'])) ?></div>
                    </div>

                    <div class="response-info-item">
                        <div class="response-info-key">Survey Version</div>
                        <div class="response-info-value">Version <?= (int) ($response['survey_version'] ?? 1) ?></div>
                    </div>

                    <div class="response-info-item">
                        <div class="response-info-key">Latest Career Record</div>
                        <div class="response-info-value"><?= ((int) $response['is_latest'] === 1) ? 'Yes' : 'No, historical response' ?></div>
                    </div>

                    <?php if ($furtherStudies !== ''): ?>
                        <div class="response-info-item">
                            <div class="response-info-key">Further Studies</div>
                            <div class="response-info-value"><?= h($furtherStudies) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($previousResponse || $nextResponse): ?>
                <div class="response-panel">
                    <div class="response-panel-title">Career Update Navigation</div>
                    <div class="response-panel-subtitle">Move between this alumni's other submissions for the same tracer survey.</div>

                    <div class="response-navigation">
                        <?php if ($previousResponse): ?>
                            <a href="view_tracer_response.php?id=<?= (int) $previousResponse['id'] ?>" class="nav-response-card">
                                <div class="nav-response-label">Older Response</div>
                                <div class="nav-response-date"><?= h(formatDateShort($previousResponse['submitted_at'])) ?></div>
                            </a>
                        <?php endif; ?>

                        <?php if ($nextResponse): ?>
                            <a href="view_tracer_response.php?id=<?= (int) $nextResponse['id'] ?>" class="nav-response-card">
                                <div class="nav-response-label">Newer Response</div>
                                <div class="nav-response-date"><?= h(formatDateShort($nextResponse['submitted_at'])) ?></div>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="response-panel">
                <div class="response-panel-title">Complete Answers</div>
                <div class="response-panel-subtitle">All tracer questions and answers submitted by this alumni.</div>

                <?php if (empty($answers)): ?>
                    <div class="text-muted">No questions found for this tracer survey.</div>
                <?php else: ?>
                    <div class="answer-list">
                        <?php foreach ($answers as $index => $answer): ?>
                            <?php
                                $answerText = trim((string) ($answer['answer_text'] ?? ''));
                                $questionType = $answer['question_type'] ?? 'text';
                            ?>
                            <div class="answer-card">
                                <div class="answer-card-header">
                                    <div class="answer-number"><?= $index + 1 ?></div>
                                    <div>
                                        <div class="answer-question"><?= h($answer['question_text']) ?></div>
                                        <?php if ((int) ($answer['is_required'] ?? 0) === 1): ?>
                                            <div class="text-muted small mt-1">Required question</div>
                                        <?php else: ?>
                                            <div class="text-muted small mt-1">Optional question</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="answer-type"><?= h($questionType) ?></div>
                                </div>

                                <div class="answer-body <?= $answerText === '' ? 'answer-empty' : '' ?>">
                                    <?= $answerText !== '' ? nl2br(h($answerText)) : 'No answer provided' ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($skills !== '' || $challenges !== ''): ?>
                <div class="response-panel">
                    <div class="response-panel-title">Skills and Challenges Snapshot</div>
                    <div class="response-panel-subtitle">Quick view of career preparation feedback.</div>

                    <div class="response-info-list">
                        <?php if ($skills !== ''): ?>
                            <div class="response-info-item">
                                <div class="response-info-key">Skills / Competencies</div>
                                <div class="response-info-value"><?= nl2br(h($skills)) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($challenges !== ''): ?>
                            <div class="response-info-item">
                                <div class="response-info-key">Challenges</div>
                                <div class="response-info-value"><?= nl2br(h($challenges)) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
