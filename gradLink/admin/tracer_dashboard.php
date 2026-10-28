<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
        'employed' => 'status-good',
        'self' => 'status-info',
        'underemployed' => 'status-warning',
        'unemployed' => 'status-danger',
        'other' => 'status-neutral',
        'unknown' => 'status-neutral'
    ];

    return $classes[$category] ?? 'status-neutral';
}

function formatDateSafe($date)
{
    if (empty($date)) {
        return '—';
    }

    return date('M j, Y', strtotime($date));
}

/*
|--------------------------------------------------------------------------
| Active Tracer Survey
|--------------------------------------------------------------------------
*/
$activeSurveyStmt = $pdo->prepare("\n    SELECT *\n    FROM tracer_surveys\n    WHERE survey_type = 'tracer'\n      AND is_active = 1\n    ORDER BY id DESC\n    LIMIT 1\n");
$activeSurveyStmt->execute();
$activeTracerSurvey = $activeSurveyStmt->fetch(PDO::FETCH_ASSOC);

$totalAlumniStmt = $pdo->query("\n    SELECT COUNT(*)\n    FROM users\n    WHERE role = 'alumni'\n      AND status = 'active'\n");
$totalAlumni = (int) $totalAlumniStmt->fetchColumn();

$courseStmt = $pdo->query("\n    SELECT DISTINCT course\n    FROM alumni_profiles\n    WHERE course IS NOT NULL\n      AND course <> ''\n    ORDER BY course ASC\n");
$courseOptions = $courseStmt->fetchAll(PDO::FETCH_COLUMN);

$yearStmt = $pdo->query("\n    SELECT DISTINCT graduation_year\n    FROM alumni_profiles\n    WHERE graduation_year IS NOT NULL\n      AND graduation_year <> ''\n    ORDER BY graduation_year DESC\n");
$yearOptions = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

$selectedCourse = trim($_GET['course'] ?? '');
$selectedYear = trim($_GET['year'] ?? '');
$selectedStatus = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');

$exportFilters = [];
if ($search !== '') {
    $exportFilters['q'] = $search;
}
if ($selectedCourse !== '') {
    $exportFilters['course'] = $selectedCourse;
}
if ($selectedYear !== '') {
    $exportFilters['year'] = $selectedYear;
}
if ($selectedStatus !== '') {
    $exportFilters['status'] = $selectedStatus;
}
$exportUrl = 'export_tracer_report.php' . (!empty($exportFilters) ? '?' . http_build_query($exportFilters) : '');

$latestRows = [];
$answerMap = [];
$records = [];
$filteredRecords = [];
$totalCareerUpdates = 0;
$totalParticipants = 0;
$responseRate = 0;
$employmentCounts = [
    'employed' => 0,
    'self' => 0,
    'underemployed' => 0,
    'unemployed' => 0,
    'other' => 0,
    'unknown' => 0
];
$furtherStudiesCount = 0;

if ($activeTracerSurvey) {
    $surveyId = (int) $activeTracerSurvey['id'];

    $updatesStmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM tracer_responses\n        WHERE survey_id = ?\n    ");
    $updatesStmt->execute([$surveyId]);
    $totalCareerUpdates = (int) $updatesStmt->fetchColumn();

    $participantsStmt = $pdo->prepare("\n        SELECT COUNT(DISTINCT alumni_id)\n        FROM tracer_responses\n        WHERE survey_id = ?\n    ");
    $participantsStmt->execute([$surveyId]);
    $totalParticipants = (int) $participantsStmt->fetchColumn();

    $responseRate = $totalAlumni > 0 ? round(($totalParticipants / $totalAlumni) * 100) : 0;

    $latestStmt = $pdo->prepare("\n        SELECT\n            tr.id AS response_id,\n            tr.alumni_id,\n            tr.submitted_at,\n            tr.survey_version,\n            u.first_name,\n            u.last_name,\n            u.email,\n            ap.student_id,\n            ap.course,\n            ap.major,\n            ap.graduation_year,\n            ap.phone\n        FROM tracer_responses tr\n        INNER JOIN (\n            SELECT alumni_id, MAX(id) AS latest_response_id\n            FROM tracer_responses\n            WHERE survey_id = ?\n            GROUP BY alumni_id\n        ) latest ON latest.latest_response_id = tr.id\n        INNER JOIN users u ON tr.alumni_id = u.id\n        LEFT JOIN alumni_profiles ap ON u.id = ap.user_id\n        WHERE tr.survey_id = ?\n        ORDER BY tr.submitted_at DESC, tr.id DESC\n    ");
    $latestStmt->execute([$surveyId, $surveyId]);
    $latestRows = $latestStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($latestRows)) {
        $responseIds = array_column($latestRows, 'response_id');
        $placeholders = implode(',', array_fill(0, count($responseIds), '?'));

        $answersStmt = $pdo->prepare("\n            SELECT\n                ta.response_id,\n                tq.question_text,\n                tq.question_type,\n                tq.sort_order,\n                ta.answer_text\n            FROM tracer_answers ta\n            INNER JOIN tracer_questions tq ON ta.question_id = tq.id\n            WHERE ta.response_id IN ($placeholders)\n            ORDER BY ta.response_id ASC, tq.sort_order ASC, tq.id ASC\n        ");
        $answersStmt->execute($responseIds);
        $allAnswers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allAnswers as $answer) {
            $answerMap[$answer['response_id']][] = $answer;
        }
    }

    foreach ($latestRows as $row) {
        $answers = $answerMap[$row['response_id']] ?? [];

        $employmentStatus = answerValue($answers, ['employment status', 'status of employment']);
        $employmentCategory = classifyEmployment($employmentStatus);
        $furtherStudies = answerValue($answers, ['further studies', 'pursuing further']);

        if (isset($employmentCounts[$employmentCategory])) {
            $employmentCounts[$employmentCategory]++;
        } else {
            $employmentCounts['other']++;
        }

        if (strtolower(trim($furtherStudies)) === 'yes') {
            $furtherStudiesCount++;
        }

        $records[] = [
            'response_id' => $row['response_id'],
            'alumni_id' => $row['alumni_id'],
            'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'email' => $row['email'] ?? '',
            'student_id' => $row['student_id'] ?? '',
            'course' => $row['course'] ?? '',
            'major' => $row['major'] ?? '',
            'graduation_year' => $row['graduation_year'] ?? '',
            'phone' => $row['phone'] ?? '',
            'submitted_at' => $row['submitted_at'],
            'survey_version' => $row['survey_version'] ?? 1,
            'answers' => $answers,
            'employment_status' => $employmentStatus,
            'employment_category' => $employmentCategory,
            'employer_name' => answerValue($answers, ['name of your employer', 'employer name', 'employer']),
            'job_role' => answerValue($answers, ['job description', 'job role', 'job title', 'role']),
            'sector' => answerValue($answers, ['sector']),
            'work_location' => answerValue($answers, ['work location', 'where is your work', 'location']),
            'further_studies' => $furtherStudies,
            'skills' => answerValue($answers, ['skills']),
            'challenges' => answerValue($answers, ['challenges'])
        ];
    }

    $filteredRecords = array_values(array_filter($records, function ($record) use ($selectedCourse, $selectedYear, $selectedStatus, $search) {
        if ($selectedCourse !== '' && $record['course'] !== $selectedCourse) {
            return false;
        }

        if ($selectedYear !== '' && (string) $record['graduation_year'] !== (string) $selectedYear) {
            return false;
        }

        if ($selectedStatus !== '' && $record['employment_category'] !== $selectedStatus) {
            return false;
        }

        if ($search !== '') {
            $haystack = strtolower(implode(' ', [
                $record['name'],
                $record['email'],
                $record['student_id'],
                $record['course'],
                $record['major'],
                $record['employment_status'],
                $record['employer_name'],
                $record['job_role'],
                $record['sector'],
                $record['work_location']
            ]));

            if (strpos($haystack, strtolower($search)) === false) {
                return false;
            }
        }

        return true;
    }));
}

$currentEmployed = $employmentCounts['employed'];
$currentUnemployed = $employmentCounts['unemployed'];
$currentSelf = $employmentCounts['self'];
$currentUnderemployed = $employmentCounts['underemployed'];
$currentKnownStatus = array_sum($employmentCounts) - $employmentCounts['unknown'];
$employmentRate = $currentKnownStatus > 0 ? round(($currentEmployed / $currentKnownStatus) * 100) : 0;

$pageTitle = 'Tracer Dashboard';
$pageSubtitle = 'Monitor alumni tracer study participation and current career outcomes.';
$activePage = 'tracer_dashboard';

require_once '../includes/dashboard_header.php';
?>

<style>
    .tracer-admin-page {
        --tracer-maroon: #800000;
        --tracer-dark-maroon: #5f0000;
        --tracer-soft-maroon: #fff3f3;
        --tracer-border: #eadada;
        --tracer-text: #231f20;
        --tracer-muted: #776b6b;
    }

    .tracer-hero {
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.22), transparent 35%),
            linear-gradient(135deg, var(--tracer-maroon), var(--tracer-dark-maroon));
        color: #fff;
        border-radius: 28px;
        padding: 30px;
        box-shadow: 0 22px 45px rgba(128, 0, 0, 0.18);
        margin-bottom: 24px;
        overflow: hidden;
        position: relative;
    }

    .tracer-hero::after {
        content: "";
        position: absolute;
        right: -80px;
        bottom: -90px;
        width: 250px;
        height: 250px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.08);
    }

    .tracer-hero-grid {
        display: grid;
        grid-template-columns: 1.5fr 0.8fr;
        gap: 22px;
        position: relative;
        z-index: 1;
        align-items: stretch;
    }

    .tracer-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.22);
        padding: 8px 13px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 700;
    }

    .tracer-hero-title {
        font-size: clamp(28px, 4vw, 42px);
        font-weight: 900;
        line-height: 1.05;
        letter-spacing: -0.9px;
        margin-top: 16px;
        max-width: 780px;
    }

    .tracer-hero-text {
        color: rgba(255, 255, 255, 0.84);
        margin-top: 12px;
        max-width: 760px;
        font-size: 15px;
        line-height: 1.7;
    }

    .tracer-hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 22px;
    }

    .tracer-btn-light,
    .tracer-btn-outline,
    .tracer-btn-maroon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 999px;
        padding: 10px 16px;
        font-weight: 800;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        font-size: 14px;
    }

    .tracer-btn-light {
        background: #ffffff;
        color: var(--tracer-maroon);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.14);
    }

    .tracer-btn-light:hover {
        color: var(--tracer-dark-maroon);
        transform: translateY(-1px);
    }

    .tracer-btn-outline {
        color: #ffffff;
        border-color: rgba(255, 255, 255, 0.35);
        background: rgba(255, 255, 255, 0.08);
    }

    .tracer-btn-outline:hover {
        color: #ffffff;
        background: rgba(255, 255, 255, 0.16);
        transform: translateY(-1px);
    }

    .tracer-btn-maroon {
        background: var(--tracer-maroon);
        color: #ffffff;
        border-color: var(--tracer-maroon);
    }

    .tracer-btn-maroon:hover {
        background: var(--tracer-dark-maroon);
        color: #ffffff;
        transform: translateY(-1px);
    }

    .tracer-hero-panel {
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
        font-weight: 800;
    }

    .hero-panel-value {
        font-size: 48px;
        font-weight: 900;
        line-height: 1;
        margin-top: 12px;
    }

    .hero-panel-note {
        color: rgba(255, 255, 255, 0.8);
        font-size: 14px;
        margin-top: 10px;
        line-height: 1.5;
    }

    .tracer-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .tracer-stat {
        background: #ffffff;
        border: 1px solid var(--tracer-border);
        border-radius: 22px;
        padding: 20px;
        box-shadow: 0 12px 28px rgba(52, 20, 20, 0.06);
        position: relative;
        overflow: hidden;
    }

    .tracer-stat::after {
        content: "";
        position: absolute;
        right: -35px;
        top: -45px;
        width: 110px;
        height: 110px;
        border-radius: 50%;
        background: var(--tracer-soft-maroon);
    }

    .tracer-stat-label {
        color: var(--tracer-muted);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.9px;
        font-weight: 900;
        position: relative;
        z-index: 1;
    }

    .tracer-stat-value {
        color: var(--tracer-text);
        font-size: 34px;
        font-weight: 900;
        margin-top: 8px;
        position: relative;
        z-index: 1;
    }

    .tracer-stat-note {
        color: var(--tracer-muted);
        font-size: 13px;
        margin-top: 3px;
        position: relative;
        z-index: 1;
    }

    .tracer-layout {
        display: grid;
        grid-template-columns: 1.15fr 0.85fr;
        gap: 22px;
        align-items: start;
    }

    .tracer-panel {
        background: #ffffff;
        border: 1px solid var(--tracer-border);
        border-radius: 24px;
        padding: 22px;
        box-shadow: 0 14px 34px rgba(52, 20, 20, 0.06);
        margin-bottom: 22px;
    }

    .tracer-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }

    .tracer-panel-title {
        font-weight: 900;
        font-size: 18px;
        color: var(--tracer-text);
        margin-bottom: 4px;
    }

    .tracer-panel-subtitle {
        color: var(--tracer-muted);
        font-size: 13px;
    }

    .tracer-filter-grid {
        display: grid;
        grid-template-columns: 1.2fr 0.9fr 0.8fr 1fr auto;
        gap: 10px;
        align-items: end;
    }

    .tracer-form-label {
        font-size: 12px;
        color: var(--tracer-muted);
        text-transform: uppercase;
        font-weight: 900;
        letter-spacing: 0.7px;
        margin-bottom: 7px;
    }

    .tracer-input,
    .tracer-select {
        width: 100%;
        border: 1px solid var(--tracer-border);
        border-radius: 14px;
        padding: 11px 13px;
        outline: none;
        color: var(--tracer-text);
        background: #fff;
        transition: all 0.2s ease;
    }

    .tracer-input:focus,
    .tracer-select:focus {
        border-color: var(--tracer-maroon);
        box-shadow: 0 0 0 4px rgba(128, 0, 0, 0.08);
    }

    .employment-list {
        display: grid;
        gap: 14px;
    }

    .employment-row {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 14px;
        align-items: center;
    }

    .employment-label {
        font-weight: 800;
        color: var(--tracer-text);
        margin-bottom: 6px;
        font-size: 14px;
    }

    .employment-track {
        height: 10px;
        background: #f5eeee;
        border-radius: 999px;
        overflow: hidden;
    }

    .employment-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--tracer-maroon), #b00020);
        border-radius: 999px;
    }

    .employment-count {
        color: var(--tracer-muted);
        font-size: 13px;
        font-weight: 800;
        min-width: 72px;
        text-align: right;
    }

    .tracer-table-wrap {
        overflow-x: auto;
    }

    .tracer-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 10px;
    }

    .tracer-table thead th {
        color: var(--tracer-muted);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        padding: 0 12px 4px;
        white-space: nowrap;
    }

    .tracer-table tbody tr {
        background: #fff;
        box-shadow: 0 10px 22px rgba(52, 20, 20, 0.045);
    }

    .tracer-table tbody td {
        padding: 15px 12px;
        border-top: 1px solid #f1e4e4;
        border-bottom: 1px solid #f1e4e4;
        vertical-align: top;
    }

    .tracer-table tbody td:first-child {
        border-left: 1px solid #f1e4e4;
        border-radius: 16px 0 0 16px;
    }

    .tracer-table tbody td:last-child {
        border-right: 1px solid #f1e4e4;
        border-radius: 0 16px 16px 0;
    }

    .alumni-name {
        font-weight: 900;
        color: var(--tracer-text);
        white-space: nowrap;
    }

    .mini-muted {
        color: var(--tracer-muted);
        font-size: 12px;
        margin-top: 3px;
    }

    .tracer-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 7px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
    }

    .status-good {
        color: #17633a;
        background: #e8f7ef;
    }

    .status-info {
        color: #0c5c6d;
        background: #e8f8fb;
    }

    .status-warning {
        color: #8a5a00;
        background: #fff6df;
    }

    .status-danger {
        color: #9b1c1c;
        background: #fff0f0;
    }

    .status-neutral {
        color: #5b5555;
        background: #f2eeee;
    }

    .answer-preview {
        max-width: 330px;
        color: var(--tracer-muted);
        font-size: 13px;
        line-height: 1.5;
    }

    .tracer-action-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-width: 132px;
    }

    .tracer-action-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 900;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid #eadada;
        background: #fffafa;
        color: var(--tracer-maroon);
        white-space: nowrap;
    }

    .tracer-action-link:hover {
        color: #ffffff;
        background: var(--tracer-maroon);
        border-color: var(--tracer-maroon);
        transform: translateY(-1px);
    }

    .tracer-action-link.secondary {
        color: #5b5555;
        background: #f7f3f3;
    }

    .tracer-action-link.secondary:hover {
        color: #ffffff;
        background: #5b5555;
        border-color: #5b5555;
    }

    .empty-state-modern {
        border: 1px dashed #e3caca;
        border-radius: 24px;
        padding: 32px;
        background: #fffafa;
        text-align: center;
    }

    .empty-icon {
        width: 64px;
        height: 64px;
        border-radius: 20px;
        background: var(--tracer-soft-maroon);
        color: var(--tracer-maroon);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        margin-bottom: 16px;
    }

    @media (max-width: 1100px) {
        .tracer-stat-grid,
        .tracer-layout,
        .tracer-hero-grid,
        .tracer-filter-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .tracer-hero,
        .tracer-panel {
            border-radius: 20px;
            padding: 20px;
        }

        .tracer-stat-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="tracer-admin-page">
    <?php if (!$activeTracerSurvey): ?>
        <div class="tracer-hero">
            <div class="tracer-hero-grid">
                <div>
                    <span class="tracer-kicker">Tracer Study</span>
                    <div class="tracer-hero-title">No active tracer survey yet</div>
                    <div class="tracer-hero-text">
                        Create or activate one survey with survey type set to Tracer Study. Once active, alumni can take the tracer survey and update their career status over time.
                    </div>
                    <div class="tracer-hero-actions">
                        <a href="create_survey.php" class="tracer-btn-light">Create Tracer Survey</a>
                        <a href="surveys.php" class="tracer-btn-outline">Manage Surveys</a>
                    </div>
                </div>
                <div class="tracer-hero-panel">
                    <div class="hero-panel-label">Current Status</div>
                    <div class="hero-panel-value">0%</div>
                    <div class="hero-panel-note">No active tracer form is available for alumni yet.</div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="tracer-hero">
            <div class="tracer-hero-grid">
                <div>
                    <span class="tracer-kicker">Active Tracer Study</span>
                    <div class="tracer-hero-title"><?= h($activeTracerSurvey['title']) ?></div>
                    <div class="tracer-hero-text">
                        Monitor current alumni career outcomes using the latest submitted career status from each alumni. Historical updates remain available through tracer responses.
                    </div>
                    <div class="tracer-hero-actions">
                        <a href="<?= h($exportUrl) ?>" class="tracer-btn-light">Export CSV</a>
                        <a href="survey_responses.php?id=<?= (int) $activeTracerSurvey['id'] ?>" class="tracer-btn-outline">View All Responses</a>
                        <a href="survey_analytics.php?id=<?= (int) $activeTracerSurvey['id'] ?>" class="tracer-btn-outline">Question Analytics</a>
                        <a href="surveys.php" class="tracer-btn-outline">Manage Surveys</a>
                    </div>
                </div>

                <div class="tracer-hero-panel">
                    <div class="hero-panel-label">Participation Rate</div>
                    <div class="hero-panel-value"><?= $responseRate ?>%</div>
                    <div class="hero-panel-note">
                        <?= $totalParticipants ?> of <?= $totalAlumni ?> active alumni have submitted at least one tracer response.
                    </div>
                </div>
            </div>
        </div>

        <div class="tracer-stat-grid">
            <div class="tracer-stat">
                <div class="tracer-stat-label">Tracer Participants</div>
                <div class="tracer-stat-value"><?= $totalParticipants ?></div>
                <div class="tracer-stat-note">Alumni with tracer record</div>
            </div>

            <div class="tracer-stat">
                <div class="tracer-stat-label">Career Updates</div>
                <div class="tracer-stat-value"><?= $totalCareerUpdates ?></div>
                <div class="tracer-stat-note">All submitted snapshots</div>
            </div>

            <div class="tracer-stat">
                <div class="tracer-stat-label">Employment Rate</div>
                <div class="tracer-stat-value"><?= $employmentRate ?>%</div>
                <div class="tracer-stat-note">Based on latest known status</div>
            </div>

            <div class="tracer-stat">
                <div class="tracer-stat-label">Further Studies</div>
                <div class="tracer-stat-value"><?= $furtherStudiesCount ?></div>
                <div class="tracer-stat-note">Currently pursuing studies</div>
            </div>
        </div>

        <div class="tracer-layout">
            <div>
                <div class="tracer-panel">
                    <div class="tracer-panel-header">
                        <div>
                            <div class="tracer-panel-title">Filter Current Career Records</div>
                            <div class="tracer-panel-subtitle">Search by alumni, course, batch, employer, role, or status.</div>
                        </div>
                    </div>

                    <form method="get" class="tracer-filter-grid">
                        <div>
                            <label class="tracer-form-label">Search</label>
                            <input type="text" name="q" class="tracer-input" value="<?= h($search) ?>" placeholder="Name, email, employer, role...">
                        </div>

                        <div>
                            <label class="tracer-form-label">Course</label>
                            <select name="course" class="tracer-select">
                                <option value="">All Courses</option>
                                <?php foreach ($courseOptions as $course): ?>
                                    <option value="<?= h($course) ?>" <?= $selectedCourse === $course ? 'selected' : '' ?>><?= h($course) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="tracer-form-label">Batch</label>
                            <select name="year" class="tracer-select">
                                <option value="">All Years</option>
                                <?php foreach ($yearOptions as $year): ?>
                                    <option value="<?= h($year) ?>" <?= (string) $selectedYear === (string) $year ? 'selected' : '' ?>><?= h($year) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="tracer-form-label">Status</label>
                            <select name="status" class="tracer-select">
                                <option value="">All Statuses</option>
                                <option value="employed" <?= $selectedStatus === 'employed' ? 'selected' : '' ?>>Employed</option>
                                <option value="self" <?= $selectedStatus === 'self' ? 'selected' : '' ?>>Self-employed / Freelance</option>
                                <option value="underemployed" <?= $selectedStatus === 'underemployed' ? 'selected' : '' ?>>Underemployed</option>
                                <option value="unemployed" <?= $selectedStatus === 'unemployed' ? 'selected' : '' ?>>Unemployed</option>
                                <option value="unknown" <?= $selectedStatus === 'unknown' ? 'selected' : '' ?>>No Status</option>
                            </select>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="tracer-btn-maroon">Apply</button>
                            <a href="<?= h($exportUrl) ?>" class="btn btn-light rounded-pill fw-bold">Export CSV</a>
                            <a href="tracer_dashboard.php" class="btn btn-light rounded-pill fw-bold">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="tracer-panel">
                    <div class="tracer-panel-header">
                        <div>
                            <div class="tracer-panel-title">Latest Career Status</div>
                            <div class="tracer-panel-subtitle">
                                Showing <?= count($filteredRecords) ?> of <?= count($records) ?> alumni with latest tracer career records.
                            </div>
                        </div>
                    </div>

                    <?php if (empty($filteredRecords)): ?>
                        <div class="empty-state-modern">
                            <div class="empty-icon">⌕</div>
                            <h5 class="fw-bold mb-2">No matching career records found</h5>
                            <div class="text-muted">Try removing filters or wait for alumni to submit their tracer survey.</div>
                        </div>
                    <?php else: ?>
                        <div class="tracer-table-wrap">
                            <table class="tracer-table">
                                <thead>
                                    <tr>
                                        <th>Alumni</th>
                                        <th>Course</th>
                                        <th>Status</th>
                                        <th>Employer / Role</th>
                                        <th>Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filteredRecords as $record): ?>
                                        <tr>
                                            <td>
                                                <div class="alumni-name"><?= h($record['name'] ?: 'Unnamed Alumni') ?></div>
                                                <div class="mini-muted"><?= h($record['email']) ?></div>
                                                <?php if (!empty($record['student_id'])): ?>
                                                    <div class="mini-muted">ID: <?= h($record['student_id']) ?></div>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <strong><?= h($record['course'] ?: '—') ?></strong>
                                                <div class="mini-muted">
                                                    <?= !empty($record['graduation_year']) ? 'Batch ' . h($record['graduation_year']) : 'No batch year' ?>
                                                </div>
                                            </td>

                                            <td>
                                                <span class="tracer-badge <?= h(badgeClassForEmployment($record['employment_category'])) ?>">
                                                    <?= h($record['employment_status'] ?: employmentLabel($record['employment_category'])) ?>
                                                </span>
                                                <?php if (!empty($record['sector'])): ?>
                                                    <div class="mini-muted mt-2"><?= h($record['sector']) ?></div>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <div class="answer-preview">
                                                    <strong><?= h($record['employer_name'] ?: 'No employer provided') ?></strong>
                                                    <?php if (!empty($record['job_role'])): ?>
                                                        <br><?= h($record['job_role']) ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($record['work_location'])): ?>
                                                        <br><span class="mini-muted"><?= h($record['work_location']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <td>
                                                <strong><?= h(formatDateSafe($record['submitted_at'])) ?></strong>
                                                <div class="mini-muted">Version <?= (int) $record['survey_version'] ?></div>
                                            </td>

                                            <td>
                                                <div class="tracer-action-list">
                                                    <a href="view_tracer_response.php?id=<?= (int) $record['response_id'] ?>" class="tracer-action-link">View Response</a>
                                                    <a href="alumni_career_timeline.php?id=<?= (int) $record['alumni_id'] ?>" class="tracer-action-link secondary">View Timeline</a>
                                                    <a href="survey_responses.php?id=<?= (int) $activeTracerSurvey['id'] ?>" class="tracer-action-link secondary">All Responses</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <div class="tracer-panel">
                    <div class="tracer-panel-header">
                        <div>
                            <div class="tracer-panel-title">Current Employment Breakdown</div>
                            <div class="tracer-panel-subtitle">Based on the latest response per alumni.</div>
                        </div>
                    </div>

                    <div class="employment-list">
                        <?php foreach ($employmentCounts as $category => $count): ?>
                            <?php
                                $percentage = count($records) > 0 ? round(($count / count($records)) * 100) : 0;
                            ?>
                            <div class="employment-row">
                                <div>
                                    <div class="employment-label"><?= h(employmentLabel($category)) ?></div>
                                    <div class="employment-track">
                                        <div class="employment-fill" style="width: <?= $percentage ?>%"></div>
                                    </div>
                                </div>
                                <div class="employment-count"><?= $count ?> • <?= $percentage ?>%</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="tracer-panel">
                    <div class="tracer-panel-header">
                        <div>
                            <div class="tracer-panel-title">Tracer Survey Details</div>
                            <div class="tracer-panel-subtitle">Current active form used by alumni.</div>
                        </div>
                    </div>

                    <div class="info-list">
                        <div class="info-item">
                            <div class="info-key">Survey Title</div>
                            <div class="info-value"><?= h($activeTracerSurvey['title']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-key">Survey Type</div>
                            <div class="info-value">Tracer Study</div>
                        </div>
                        <div class="info-item">
                            <div class="info-key">Status</div>
                            <div class="info-value">Active</div>
                        </div>
                        <div class="info-item">
                            <div class="info-key">Created</div>
                            <div class="info-value"><?= h(formatDateSafe($activeTracerSurvey['created_at'] ?? '')) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-key">Version</div>
                            <div class="info-value"><?= (int) ($activeTracerSurvey['version'] ?? 1) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
