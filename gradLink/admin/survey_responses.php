<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$survey_id = $_GET['id'] ?? null;

if (!$survey_id || !ctype_digit((string) $survey_id)) {
    header('Location: surveys.php');
    exit;
}

$surveyStmt = $pdo->prepare("\n    SELECT *\n    FROM tracer_surveys\n    WHERE id = ?\n    LIMIT 1\n");
$surveyStmt->execute([$survey_id]);
$survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    header('Location: surveys.php');
    exit;
}

function sr_h($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function sr_short($text, $limit = 180) {
    $text = trim(strip_tags((string) $text));
    if ($text === '') {
        return '—';
    }
    if (function_exists('mb_strlen') && mb_strlen($text) > $limit) {
        return mb_substr($text, 0, $limit) . '...';
    }
    if (!function_exists('mb_strlen') && strlen($text) > $limit) {
        return substr($text, 0, $limit) . '...';
    }
    return $text;
}

function sr_pick_answer(array $answers, array $keywords) {
    foreach ($answers as $answer) {
        $question = strtolower((string) ($answer['question_text'] ?? ''));
        foreach ($keywords as $keyword) {
            if (strpos($question, strtolower($keyword)) !== false) {
                $value = trim((string) ($answer['answer_text'] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
    }
    return '';
}

$surveyType = $survey['survey_type'] ?? 'general';
$isTracerSurvey = $surveyType === 'tracer';

$questionCountStmt = $pdo->prepare("\n    SELECT COUNT(*)\n    FROM tracer_questions\n    WHERE survey_id = ?\n");
$questionCountStmt->execute([$survey_id]);
$questionCount = (int) $questionCountStmt->fetchColumn();

$responseStmt = $pdo->prepare("\n    SELECT\n        tr.id AS response_id,\n        tr.alumni_id,\n        tr.submitted_at,\n        tr.survey_version,\n        tr.is_latest,\n        u.first_name,\n        u.last_name,\n        u.email,\n        ap.student_id,\n        ap.course,\n        ap.major,\n        ap.graduation_year\n    FROM tracer_responses tr\n    INNER JOIN users u ON tr.alumni_id = u.id\n    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id\n    WHERE tr.survey_id = ?\n    ORDER BY tr.submitted_at DESC, tr.id DESC\n");
$responseStmt->execute([$survey_id]);
$allResponses = $responseStmt->fetchAll(PDO::FETCH_ASSOC);

$answersByResponse = [];
if (!empty($allResponses)) {
    $responseIds = array_column($allResponses, 'response_id');
    $placeholders = implode(',', array_fill(0, count($responseIds), '?'));

    $answersStmt = $pdo->prepare("\n        SELECT\n            ta.response_id,\n            tq.question_text,\n            tq.question_type,\n            tq.sort_order,\n            ta.answer_text\n        FROM tracer_answers ta\n        INNER JOIN tracer_questions tq ON ta.question_id = tq.id\n        WHERE ta.response_id IN ($placeholders)\n        ORDER BY ta.response_id DESC, tq.sort_order ASC, tq.id ASC\n    ");
    $answersStmt->execute($responseIds);
    $answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($answers as $answer) {
        $answersByResponse[$answer['response_id']][] = $answer;
    }
}

foreach ($allResponses as &$response) {
    $responseAnswers = $answersByResponse[$response['response_id']] ?? [];
    $response['_answers'] = $responseAnswers;
    $response['_employment_status'] = sr_pick_answer($responseAnswers, [
        'employment status',
        'status of employment',
        'present employment',
        'status at present'
    ]);
    $response['_employer'] = sr_pick_answer($responseAnswers, [
        'employer name',
        'company name',
        'employer',
        'company'
    ]);
    $response['_role'] = sr_pick_answer($responseAnswers, [
        'job title',
        'job role',
        'job description',
        'position',
        'role'
    ]);
    $response['_location'] = sr_pick_answer($responseAnswers, [
        'work location',
        'location'
    ]);
}
unset($response);

$search = trim($_GET['search'] ?? '');
$courseFilter = trim($_GET['course'] ?? '');
$yearFilter = trim($_GET['graduation_year'] ?? '');
$statusFilter = trim($_GET['employment_status'] ?? '');
$latestOnly = isset($_GET['latest_only']) && $_GET['latest_only'] === '1';

$courses = [];
$years = [];
$statuses = [];
$uniqueAlumni = [];
$latestCount = 0;
$recentCount = 0;

$thirtyDaysAgo = strtotime('-30 days');
foreach ($allResponses as $response) {
    if (!empty($response['course'])) {
        $courses[$response['course']] = $response['course'];
    }
    if (!empty($response['graduation_year'])) {
        $years[$response['graduation_year']] = $response['graduation_year'];
    }
    if (!empty($response['_employment_status'])) {
        $statuses[$response['_employment_status']] = $response['_employment_status'];
    }
    if (!empty($response['alumni_id'])) {
        $uniqueAlumni[$response['alumni_id']] = true;
    }
    if ((int) ($response['is_latest'] ?? 0) === 1) {
        $latestCount++;
    }
    if (!empty($response['submitted_at']) && strtotime($response['submitted_at']) >= $thirtyDaysAgo) {
        $recentCount++;
    }
}
ksort($courses);
rsort($years);
ksort($statuses);

$filteredResponses = array_values(array_filter($allResponses, function ($response) use ($search, $courseFilter, $yearFilter, $statusFilter, $latestOnly) {
    if ($courseFilter !== '' && (string) ($response['course'] ?? '') !== $courseFilter) {
        return false;
    }

    if ($yearFilter !== '' && (string) ($response['graduation_year'] ?? '') !== $yearFilter) {
        return false;
    }

    if ($statusFilter !== '' && (string) ($response['_employment_status'] ?? '') !== $statusFilter) {
        return false;
    }

    if ($latestOnly && (int) ($response['is_latest'] ?? 0) !== 1) {
        return false;
    }

    if ($search !== '') {
        $haystack = strtolower(trim(
            ($response['first_name'] ?? '') . ' ' .
            ($response['last_name'] ?? '') . ' ' .
            ($response['email'] ?? '') . ' ' .
            ($response['student_id'] ?? '') . ' ' .
            ($response['course'] ?? '') . ' ' .
            ($response['_employment_status'] ?? '') . ' ' .
            ($response['_employer'] ?? '') . ' ' .
            ($response['_role'] ?? '')
        ));

        foreach (($response['_answers'] ?? []) as $answer) {
            $haystack .= ' ' . strtolower((string) ($answer['answer_text'] ?? ''));
        }

        if (strpos($haystack, strtolower($search)) === false) {
            return false;
        }
    }

    return true;
}));

$totalResponses = count($allResponses);
$totalParticipants = count($uniqueAlumni);
$filteredCount = count($filteredResponses);

$pageTitle = 'Survey Responses';
$pageSubtitle = 'Review alumni answers and submission history for this survey.';
$activePage = 'surveys';

require_once '../includes/dashboard_header.php';
?>

<style>
    .responses-shell {
        display: grid;
        gap: 22px;
    }

    .responses-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 30px;
        color: #fff;
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.22), transparent 32%),
            linear-gradient(135deg, #7b1113 0%, #5b0b0d 52%, #310506 100%);
        box-shadow: 0 18px 42px rgba(128, 0, 0, 0.22);
    }

    .responses-hero::after {
        content: "";
        position: absolute;
        right: -80px;
        bottom: -110px;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.09);
    }

    .responses-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 22px;
        align-items: center;
    }

    .eyebrow-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 13px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.22);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.6px;
        text-transform: uppercase;
    }

    .responses-title {
        margin: 14px 0 8px;
        font-size: clamp(28px, 4vw, 44px);
        line-height: 1.05;
        font-weight: 900;
        letter-spacing: -1px;
    }

    .responses-subtitle {
        max-width: 820px;
        color: rgba(255, 255, 255, 0.82);
        font-size: 15px;
        line-height: 1.7;
        margin: 0;
    }

    .hero-actions-modern {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .btn-hero-light,
    .btn-hero-outline {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 42px;
        padding: 10px 15px;
        border-radius: 14px;
        text-decoration: none;
        font-weight: 800;
        font-size: 13px;
        transition: all 0.2s ease;
        white-space: nowrap;
    }

    .btn-hero-light {
        color: #6f0f12;
        background: #ffffff;
        box-shadow: 0 12px 26px rgba(0, 0, 0, 0.16);
    }

    .btn-hero-light:hover {
        color: #4b0709;
        transform: translateY(-1px);
    }

    .btn-hero-outline {
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.32);
        background: rgba(255, 255, 255, 0.08);
    }

    .btn-hero-outline:hover {
        color: #ffffff;
        background: rgba(255, 255, 255, 0.16);
        transform: translateY(-1px);
    }

    .response-stats-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
    }

    .response-stat-card {
        border: 1px solid #f1dddd;
        border-radius: 22px;
        padding: 20px;
        background: #ffffff;
        box-shadow: 0 12px 32px rgba(87, 15, 17, 0.06);
    }

    .response-stat-label {
        color: #856d6d;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.7px;
    }

    .response-stat-value {
        margin-top: 8px;
        color: #2d1d1d;
        font-size: 30px;
        font-weight: 900;
        line-height: 1;
    }

    .response-stat-note {
        margin-top: 8px;
        color: #8c7777;
        font-size: 13px;
    }

    .responses-card {
        border: 1px solid #f1dddd;
        border-radius: 24px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(87, 15, 17, 0.06);
        overflow: hidden;
    }

    .responses-card-header {
        padding: 20px 22px;
        border-bottom: 1px solid #f4e6e6;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
    }

    .responses-card-title {
        margin: 0;
        color: #2d1d1d;
        font-size: 18px;
        font-weight: 900;
    }

    .responses-card-subtitle {
        margin: 4px 0 0;
        color: #8c7777;
        font-size: 13px;
    }

    .filter-grid {
        padding: 20px 22px;
        display: grid;
        grid-template-columns: 1.3fr repeat(3, minmax(140px, 0.6fr)) auto;
        gap: 12px;
        align-items: end;
    }

    .filter-field label {
        color: #745f5f;
        font-size: 12px;
        font-weight: 800;
        margin-bottom: 7px;
        display: block;
    }

    .filter-field input,
    .filter-field select {
        width: 100%;
        border: 1px solid #ead7d7;
        border-radius: 14px;
        padding: 11px 13px;
        font-size: 14px;
        outline: none;
        background: #fffafa;
        color: #2d1d1d;
    }

    .filter-field input:focus,
    .filter-field select:focus {
        border-color: #8f1d21;
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(128, 0, 0, 0.08);
    }

    .filter-check {
        display: flex;
        align-items: center;
        gap: 8px;
        padding-bottom: 12px;
        color: #6b5555;
        font-size: 13px;
        font-weight: 700;
        white-space: nowrap;
    }

    .filter-actions {
        display: flex;
        gap: 8px;
    }

    .btn-filter-primary,
    .btn-filter-light {
        border: 0;
        min-height: 43px;
        padding: 10px 14px;
        border-radius: 14px;
        font-size: 13px;
        font-weight: 900;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-filter-primary {
        background: linear-gradient(135deg, #8b1116, #5f0b0e);
        color: #ffffff;
        box-shadow: 0 12px 24px rgba(128, 0, 0, 0.18);
    }

    .btn-filter-light {
        background: #fff4f4;
        color: #7b1113;
        border: 1px solid #f0dada;
    }

    .response-list {
        display: grid;
        gap: 16px;
    }

    .response-item {
        border: 1px solid #f0dddd;
        border-radius: 24px;
        background: #ffffff;
        box-shadow: 0 12px 30px rgba(87, 15, 17, 0.06);
        overflow: hidden;
    }

    .response-item-main {
        padding: 22px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 16px;
    }

    .responder-name {
        margin: 0;
        color: #2d1d1d;
        font-size: 19px;
        font-weight: 900;
    }

    .responder-meta {
        margin-top: 7px;
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        color: #806a6a;
        font-size: 13px;
    }

    .soft-badge,
    .type-badge,
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 900;
        line-height: 1;
        white-space: nowrap;
    }

    .type-badge.tracer {
        color: #7b1113;
        background: #fff1f1;
        border: 1px solid #f1d4d4;
    }

    .type-badge.general {
        color: #384152;
        background: #f3f5f8;
        border: 1px solid #e3e8ef;
    }

    .status-badge.latest {
        color: #12633c;
        background: #eaf8f0;
        border: 1px solid #cbeedb;
    }

    .status-badge.history {
        color: #7a5c12;
        background: #fff8df;
        border: 1px solid #f1dfaa;
    }

    .soft-badge {
        color: #6e5656;
        background: #fff7f7;
        border: 1px solid #f0dddd;
    }

    .summary-grid {
        margin-top: 18px;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .summary-pill {
        border: 1px solid #f1dddd;
        border-radius: 16px;
        background: #fffafa;
        padding: 12px;
        min-height: 78px;
    }

    .summary-label {
        color: #9a8585;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.6px;
    }

    .summary-value {
        margin-top: 6px;
        color: #2d1d1d;
        font-size: 13px;
        font-weight: 800;
        line-height: 1.35;
    }

    .response-actions-modern {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-content: flex-start;
    }

    .btn-action-primary,
    .btn-action-light {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 13px;
        min-height: 40px;
        padding: 9px 12px;
        font-size: 12px;
        font-weight: 900;
        text-decoration: none;
        white-space: nowrap;
    }

    .btn-action-primary {
        color: #ffffff;
        background: linear-gradient(135deg, #8b1116, #5f0b0e);
    }

    .btn-action-light {
        color: #7b1113;
        background: #fff4f4;
        border: 1px solid #f0dada;
    }

    .response-answers-preview {
        border-top: 1px solid #f4e6e6;
        padding: 0 22px 22px;
    }

    .answer-details {
        margin-top: 0;
    }

    .answer-details summary {
        cursor: pointer;
        color: #7b1113;
        font-weight: 900;
        padding: 14px 0 4px;
        list-style: none;
    }

    .answer-details summary::-webkit-details-marker {
        display: none;
    }

    .answer-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-top: 12px;
    }

    .answer-box {
        border: 1px solid #f0dddd;
        border-radius: 16px;
        background: #fffafa;
        padding: 13px;
    }

    .answer-question {
        color: #7a6464;
        font-size: 12px;
        font-weight: 900;
        line-height: 1.4;
    }

    .answer-text {
        margin-top: 7px;
        color: #2d1d1d;
        font-size: 14px;
        line-height: 1.55;
        white-space: pre-wrap;
    }

    .empty-state-modern {
        text-align: center;
        padding: 52px 22px;
        border: 1px dashed #e6caca;
        border-radius: 24px;
        background: #fffafa;
        color: #806a6a;
    }

    .empty-state-icon {
        width: 62px;
        height: 62px;
        margin: 0 auto 16px;
        border-radius: 22px;
        background: #fff1f1;
        color: #7b1113;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        font-weight: 900;
    }

    @media (max-width: 1100px) {
        .response-stats-grid,
        .summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .filter-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 768px) {
        .responses-hero {
            padding: 24px;
        }

        .responses-hero-grid,
        .response-item-main,
        .filter-grid,
        .answer-grid,
        .response-stats-grid,
        .summary-grid {
            grid-template-columns: 1fr;
        }

        .hero-actions-modern,
        .response-actions-modern,
        .filter-actions {
            justify-content: flex-start;
        }
    }
</style>

<div class="responses-shell fade-in">
    <section class="responses-hero">
        <div class="responses-hero-grid">
            <div>
                <span class="eyebrow-pill">
                    <?= $isTracerSurvey ? 'Tracer Study Responses' : 'General Survey Responses' ?>
                </span>

                <h1 class="responses-title"><?= sr_h($survey['title']) ?></h1>

                <p class="responses-subtitle">
                    <?= !empty($survey['description'])
                        ? nl2br(sr_h($survey['description']))
                        : 'Review submitted alumni answers, inspect response details, and monitor participation for this survey.' ?>
                </p>
            </div>

            <div class="hero-actions-modern">
                <a href="surveys.php" class="btn-hero-outline">Back to Surveys</a>
                <a href="survey_analytics.php?id=<?= (int) $survey_id ?>" class="btn-hero-light">View Analytics</a>
                <?php if ($isTracerSurvey): ?>
                    <a href="tracer_dashboard.php" class="btn-hero-outline">Tracer Dashboard</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="response-stats-grid">
        <div class="response-stat-card">
            <div class="response-stat-label">Total Responses</div>
            <div class="response-stat-value"><?= number_format($totalResponses) ?></div>
            <div class="response-stat-note">All submissions for this survey</div>
        </div>

        <div class="response-stat-card">
            <div class="response-stat-label">Participants</div>
            <div class="response-stat-value"><?= number_format($totalParticipants) ?></div>
            <div class="response-stat-note">Unique alumni respondents</div>
        </div>

        <div class="response-stat-card">
            <div class="response-stat-label">Questions</div>
            <div class="response-stat-value"><?= number_format($questionCount) ?></div>
            <div class="response-stat-note">Items in this survey form</div>
        </div>

        <div class="response-stat-card">
            <div class="response-stat-label">Recent Updates</div>
            <div class="response-stat-value"><?= number_format($recentCount) ?></div>
            <div class="response-stat-note">Submitted in the last 30 days</div>
        </div>
    </section>

    <section class="responses-card">
        <div class="responses-card-header">
            <div>
                <h2 class="responses-card-title">Response Filters</h2>
                <p class="responses-card-subtitle">Search, filter, and review submitted answers faster.</p>
            </div>
            <div>
                <span class="type-badge <?= $isTracerSurvey ? 'tracer' : 'general' ?>">
                    <?= $isTracerSurvey ? 'Tracer Study' : 'General Survey' ?>
                </span>
            </div>
        </div>

        <form method="GET" class="filter-grid">
            <input type="hidden" name="id" value="<?= (int) $survey_id ?>">

            <div class="filter-field">
                <label>Search</label>
                <input type="text" name="search" value="<?= sr_h($search) ?>" placeholder="Name, email, student ID, employer, answer...">
            </div>

            <div class="filter-field">
                <label>Course</label>
                <select name="course">
                    <option value="">All courses</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= sr_h($course) ?>" <?= $courseFilter === (string) $course ? 'selected' : '' ?>>
                            <?= sr_h($course) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label>Graduation Year</label>
                <select name="graduation_year">
                    <option value="">All years</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= sr_h($year) ?>" <?= $yearFilter === (string) $year ? 'selected' : '' ?>>
                            <?= sr_h($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label>Employment Status</label>
                <select name="employment_status">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= sr_h($status) ?>" <?= $statusFilter === (string) $status ? 'selected' : '' ?>>
                            <?= sr_h($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label class="filter-check">
                <input type="checkbox" name="latest_only" value="1" <?= $latestOnly ? 'checked' : '' ?>>
                Latest only
            </label>

            <div class="filter-actions">
                <button type="submit" class="btn-filter-primary">Apply</button>
                <a href="survey_responses.php?id=<?= (int) $survey_id ?>" class="btn-filter-light">Reset</a>
            </div>
        </form>
    </section>

    <section class="responses-card" style="padding: 22px;">
        <div class="responses-card-header" style="padding: 0 0 18px; border-bottom: 0;">
            <div>
                <h2 class="responses-card-title">Submitted Responses</h2>
                <p class="responses-card-subtitle">
                    Showing <?= number_format($filteredCount) ?> of <?= number_format($totalResponses) ?> response<?= $totalResponses === 1 ? '' : 's' ?>.
                </p>
            </div>
        </div>

        <?php if (empty($filteredResponses)): ?>
            <div class="empty-state-modern">
                <div class="empty-state-icon">!</div>
                <h3 style="font-weight: 900; color: #2d1d1d; margin-bottom: 8px;">No responses found</h3>
                <p style="margin: 0;">There are no submissions matching your current filters.</p>
            </div>
        <?php else: ?>
            <div class="response-list">
                <?php foreach ($filteredResponses as $response): ?>
                    <?php
                        $fullName = trim(($response['first_name'] ?? '') . ' ' . ($response['last_name'] ?? ''));
                        $answers = $response['_answers'] ?? [];
                        $submittedDate = !empty($response['submitted_at']) ? date('M j, Y', strtotime($response['submitted_at'])) : '—';
                        $submittedTime = !empty($response['submitted_at']) ? date('g:i A', strtotime($response['submitted_at'])) : '';
                        $isLatest = (int) ($response['is_latest'] ?? 0) === 1;
                    ?>

                    <article class="response-item" id="response-<?= (int) $response['response_id'] ?>">
                        <div class="response-item-main">
                            <div>
                                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
                                    <?php if ($isTracerSurvey): ?>
                                        <span class="status-badge <?= $isLatest ? 'latest' : 'history' ?>">
                                            <?= $isLatest ? 'Current / Latest' : 'Historical Update' ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="soft-badge">Submitted <?= sr_h($submittedDate) ?> <?= $submittedTime ? 'at ' . sr_h($submittedTime) : '' ?></span>
                                    <?php if (!empty($response['survey_version'])): ?>
                                        <span class="soft-badge">Version <?= sr_h($response['survey_version']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <h3 class="responder-name"><?= sr_h($fullName !== '' ? $fullName : 'Unnamed Alumni') ?></h3>
                                <div class="responder-meta">
                                    <span><?= sr_h($response['email'] ?? '—') ?></span>
                                    <?php if (!empty($response['student_id'])): ?>
                                        <span>• ID: <?= sr_h($response['student_id']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($response['course'])): ?>
                                        <span>• <?= sr_h($response['course']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($response['graduation_year'])): ?>
                                        <span>• Batch <?= sr_h($response['graduation_year']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="summary-grid">
                                    <div class="summary-pill">
                                        <div class="summary-label">Employment</div>
                                        <div class="summary-value"><?= sr_h($response['_employment_status'] ?: 'Not specified') ?></div>
                                    </div>
                                    <div class="summary-pill">
                                        <div class="summary-label">Employer</div>
                                        <div class="summary-value"><?= sr_h($response['_employer'] ?: 'Not specified') ?></div>
                                    </div>
                                    <div class="summary-pill">
                                        <div class="summary-label">Role / Position</div>
                                        <div class="summary-value"><?= sr_h($response['_role'] ?: 'Not specified') ?></div>
                                    </div>
                                    <div class="summary-pill">
                                        <div class="summary-label">Location</div>
                                        <div class="summary-value"><?= sr_h($response['_location'] ?: 'Not specified') ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="response-actions-modern">
                                <?php if ($isTracerSurvey): ?>
                                    <a href="view_tracer_response.php?id=<?= (int) $response['response_id'] ?>" class="btn-action-primary">View Response</a>
                                    <a href="alumni_career_timeline.php?id=<?= (int) $response['alumni_id'] ?>" class="btn-action-light">View Timeline</a>
                                <?php else: ?>
                                    <a href="#response-<?= (int) $response['response_id'] ?>" class="btn-action-primary">View Details</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="response-answers-preview">
                            <details class="answer-details">
                                <summary>View all answers</summary>
                                <div class="answer-grid">
                                    <?php foreach ($answers as $answer): ?>
                                        <div class="answer-box">
                                            <div class="answer-question"><?= sr_h($answer['question_text']) ?></div>
                                            <div class="answer-text"><?= sr_h($answer['answer_text'] !== '' ? $answer['answer_text'] : '—') ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
