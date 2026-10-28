<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$survey_id = $_GET['id'] ?? null;

if (!$survey_id || !ctype_digit((string)$survey_id)) {
    header('Location: surveys.php');
    exit;
}

$survey_id = (int)$survey_id;

/*
|--------------------------------------------------------------------------
| Load survey
|--------------------------------------------------------------------------
*/
$surveyStmt = $pdo->prepare("SELECT * FROM tracer_surveys WHERE id = ? LIMIT 1");
$surveyStmt->execute([$survey_id]);
$survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    header('Location: surveys.php');
    exit;
}

$isTracer = (($survey['survey_type'] ?? 'general') === 'tracer');
$scope = $_GET['scope'] ?? ($isTracer ? 'current' : 'all');
if (!in_array($scope, ['current', 'all'], true)) {
    $scope = $isTracer ? 'current' : 'all';
}

$currentOnly = $isTracer && $scope === 'current';

function scopeUrl($surveyId, $scope) {
    return 'survey_analytics.php?id=' . urlencode((string)$surveyId) . '&scope=' . urlencode($scope);
}

$responseWhere = "tr.survey_id = ?";
$responseParams = [$survey_id];

if ($currentOnly) {
    $responseWhere .= " AND tr.is_latest = 1";
}

/*
|--------------------------------------------------------------------------
| Basic counts
|--------------------------------------------------------------------------
*/
$totalAlumniStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni'");
$totalAlumni = (int)$totalAlumniStmt->fetchColumn();

$totalResponsesStmt = $pdo->prepare("SELECT COUNT(*) FROM tracer_responses tr WHERE {$responseWhere}");
$totalResponsesStmt->execute($responseParams);
$totalResponses = (int)$totalResponsesStmt->fetchColumn();

$uniqueParticipantsStmt = $pdo->prepare("SELECT COUNT(DISTINCT tr.alumni_id) FROM tracer_responses tr WHERE {$responseWhere}");
$uniqueParticipantsStmt->execute($responseParams);
$uniqueParticipants = (int)$uniqueParticipantsStmt->fetchColumn();

$responseRate = $totalAlumni > 0 ? round(($uniqueParticipants / $totalAlumni) * 100) : 0;

$latestSubmissionStmt = $pdo->prepare("SELECT MAX(tr.submitted_at) FROM tracer_responses tr WHERE {$responseWhere}");
$latestSubmissionStmt->execute($responseParams);
$latestSubmission = $latestSubmissionStmt->fetchColumn();

$recentUpdatesStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tracer_responses tr
    WHERE {$responseWhere}
      AND tr.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$recentUpdatesStmt->execute($responseParams);
$recentUpdates = (int)$recentUpdatesStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Load recent respondents
|--------------------------------------------------------------------------
*/
$recentRespondentsStmt = $pdo->prepare("
    SELECT 
        tr.id AS response_id,
        tr.submitted_at,
        tr.is_latest,
        u.first_name,
        u.last_name,
        u.email,
        ap.student_id,
        ap.course,
        ap.major,
        ap.graduation_year
    FROM tracer_responses tr
    INNER JOIN users u ON tr.alumni_id = u.id
    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
    WHERE {$responseWhere}
    ORDER BY tr.submitted_at DESC, tr.id DESC
    LIMIT 6
");
$recentRespondentsStmt->execute($responseParams);
$recentRespondents = $recentRespondentsStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Load questions
|--------------------------------------------------------------------------
*/
$questionsStmt = $pdo->prepare("
    SELECT *
    FROM tracer_questions
    WHERE survey_id = ?
    ORDER BY sort_order ASC, id ASC
");
$questionsStmt->execute([$survey_id]);
$questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

$requiredQuestions = 0;
foreach ($questions as $q) {
    if ((int)($q['is_required'] ?? 0) === 1) {
        $requiredQuestions++;
    }
}

/*
|--------------------------------------------------------------------------
| Build analytics per question
|--------------------------------------------------------------------------
*/
$questionAnalytics = [];
$choiceTypes = ['radio', 'select', 'checkbox'];
$totalAnsweredInputs = 0;
$employmentBreakdown = [];
$furtherStudiesCount = 0;

foreach ($questions as $question) {
    $questionId = (int)$question['id'];
    $questionType = $question['question_type'];
    $questionText = (string)$question['question_text'];

    $answeredStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tracer_answers ta
        INNER JOIN tracer_responses tr ON ta.response_id = tr.id
        WHERE ta.question_id = ?
          AND {$responseWhere}
          AND ta.answer_text IS NOT NULL
          AND ta.answer_text <> ''
    ");
    $answeredStmt->execute(array_merge([$questionId], $responseParams));
    $answeredCount = (int)$answeredStmt->fetchColumn();
    $totalAnsweredInputs += $answeredCount;

    if (in_array($questionType, $choiceTypes, true)) {
        $optionStmt = $pdo->prepare("
            SELECT option_text
            FROM tracer_question_options
            WHERE question_id = ?
            ORDER BY id ASC
        ");
        $optionStmt->execute([$questionId]);
        $options = $optionStmt->fetchAll(PDO::FETCH_COLUMN);

        $countsStmt = $pdo->prepare("
            SELECT ta.answer_text, COUNT(*) AS total
            FROM tracer_answers ta
            INNER JOIN tracer_responses tr ON ta.response_id = tr.id
            WHERE ta.question_id = ?
              AND {$responseWhere}
              AND ta.answer_text IS NOT NULL
              AND ta.answer_text <> ''
            GROUP BY ta.answer_text
            ORDER BY total DESC, ta.answer_text ASC
        ");
        $countsStmt->execute(array_merge([$questionId], $responseParams));
        $rawCounts = $countsStmt->fetchAll(PDO::FETCH_ASSOC);

        $countMap = [];
        foreach ($rawCounts as $row) {
            $countMap[$row['answer_text']] = (int)$row['total'];
        }

        $finalCounts = [];
        foreach ($options as $option) {
            $finalCounts[] = [
                'label' => $option,
                'count' => $countMap[$option] ?? 0
            ];
            unset($countMap[$option]);
        }

        // Keep any legacy/free-form answers that were not listed as official options.
        foreach ($countMap as $label => $count) {
            $finalCounts[] = [
                'label' => $label,
                'count' => $count
            ];
        }

        usort($finalCounts, function ($a, $b) {
            if ($a['count'] === $b['count']) {
                return strcmp($a['label'], $b['label']);
            }
            return $b['count'] <=> $a['count'];
        });

        if ($isTracer && preg_match('/employment status|status of employment/i', $questionText)) {
            $employmentBreakdown = $finalCounts;
        }

        if ($isTracer && preg_match('/further studies|pursuing further/i', $questionText)) {
            foreach ($finalCounts as $row) {
                if (preg_match('/^yes\b/i', $row['label'])) {
                    $furtherStudiesCount += (int)$row['count'];
                }
            }
        }

        $questionAnalytics[] = [
            'question' => $question,
            'type' => 'choice',
            'answered' => $answeredCount,
            'data' => $finalCounts
        ];
    } else {
        $textStmt = $pdo->prepare("
            SELECT ta.answer_text, tr.submitted_at, u.first_name, u.last_name
            FROM tracer_answers ta
            INNER JOIN tracer_responses tr ON ta.response_id = tr.id
            INNER JOIN users u ON tr.alumni_id = u.id
            WHERE ta.question_id = ?
              AND {$responseWhere}
              AND ta.answer_text IS NOT NULL
              AND ta.answer_text <> ''
            ORDER BY tr.submitted_at DESC, ta.id DESC
            LIMIT 12
        ");
        $textStmt->execute(array_merge([$questionId], $responseParams));
        $textAnswers = $textStmt->fetchAll(PDO::FETCH_ASSOC);

        $questionAnalytics[] = [
            'question' => $question,
            'type' => 'text',
            'answered' => $answeredCount,
            'data' => $textAnswers
        ];
    }
}

$dominantEmployment = null;
if (!empty($employmentBreakdown)) {
    $dominantEmployment = $employmentBreakdown[0];
}

$pageTitle = 'Survey Analytics';
$pageSubtitle = 'Review response trends and question-level summaries.';
$activePage = 'surveys';

require_once '../includes/dashboard_header.php';
?>

<style>
    :root {
        --analytics-maroon: #800000;
        --analytics-dark: #5f0000;
        --analytics-soft: #fff5f5;
        --analytics-border: #eadada;
        --analytics-text: #241818;
        --analytics-muted: #766767;
    }

    .analytics-hero {
        background: linear-gradient(135deg, #800000 0%, #5f0000 55%, #3c0000 100%);
        color: #fff;
        border-radius: 28px;
        padding: 30px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 18px 45px rgba(128, 0, 0, 0.22);
        margin-bottom: 24px;
    }

    .analytics-hero::after {
        content: '';
        position: absolute;
        width: 280px;
        height: 280px;
        right: -80px;
        top: -100px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.10);
    }

    .analytics-hero-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.6fr) minmax(280px, 0.65fr);
        gap: 24px;
        align-items: stretch;
        position: relative;
        z-index: 1;
    }

    .analytics-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 999px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .analytics-title {
        font-size: clamp(28px, 4vw, 42px);
        font-weight: 900;
        letter-spacing: -0.8px;
        margin: 16px 0 10px;
        line-height: 1.05;
    }

    .analytics-subtitle {
        color: rgba(255, 255, 255, 0.82);
        font-size: 15px;
        max-width: 760px;
        line-height: 1.65;
    }

    .analytics-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 22px;
    }

    .analytics-btn-primary,
    .analytics-btn-light,
    .analytics-btn-outline {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 14px;
        padding: 11px 16px;
        font-weight: 800;
        text-decoration: none;
        transition: all .2s ease;
        border: 1px solid transparent;
    }

    .analytics-btn-primary {
        background: var(--analytics-maroon);
        color: #fff;
        box-shadow: 0 10px 24px rgba(128, 0, 0, 0.20);
    }

    .analytics-btn-primary:hover {
        background: var(--analytics-dark);
        color: #fff;
        transform: translateY(-1px);
    }

    .analytics-btn-light {
        background: #fff;
        color: var(--analytics-maroon);
        border-color: rgba(255, 255, 255, .7);
    }

    .analytics-btn-light:hover {
        color: var(--analytics-maroon);
        transform: translateY(-1px);
    }

    .analytics-btn-outline {
        color: #fff;
        border-color: rgba(255, 255, 255, 0.34);
        background: rgba(255, 255, 255, 0.08);
    }

    .analytics-btn-outline:hover {
        background: rgba(255, 255, 255, 0.15);
        color: #fff;
    }

    .analytics-rate-card {
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.20);
        border-radius: 22px;
        padding: 22px;
        backdrop-filter: blur(12px);
    }

    .analytics-rate-label {
        color: rgba(255, 255, 255, 0.78);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .8px;
        font-weight: 800;
    }

    .analytics-rate-value {
        font-size: 52px;
        font-weight: 900;
        line-height: 1;
        margin: 14px 0;
    }

    .analytics-rate-bar {
        height: 10px;
        background: rgba(255, 255, 255, 0.18);
        border-radius: 999px;
        overflow: hidden;
        margin: 16px 0 12px;
    }

    .analytics-rate-fill {
        height: 100%;
        background: #fff;
        border-radius: 999px;
    }

    .analytics-muted-light {
        color: rgba(255, 255, 255, 0.76);
        font-size: 13px;
        line-height: 1.5;
    }

    .analytics-badges {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 14px;
    }

    .analytics-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        border: 1px solid #eddcdc;
        background: #fff;
        color: var(--analytics-maroon);
    }

    .analytics-badge.soft {
        background: #fff4f4;
        color: #6e0b0b;
    }

    .analytics-badge.dark {
        background: var(--analytics-maroon);
        color: #fff;
        border-color: var(--analytics-maroon);
    }

    .analytics-grid-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 22px;
    }

    .analytics-stat {
        background: #fff;
        border: 1px solid var(--analytics-border);
        border-radius: 20px;
        padding: 18px;
        box-shadow: 0 10px 28px rgba(128, 0, 0, 0.06);
    }

    .analytics-stat-label {
        color: var(--analytics-muted);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .7px;
    }

    .analytics-stat-value {
        color: var(--analytics-text);
        font-size: 30px;
        font-weight: 900;
        margin-top: 8px;
        line-height: 1;
    }

    .analytics-stat-note {
        color: var(--analytics-muted);
        font-size: 13px;
        margin-top: 8px;
    }

    .analytics-panel {
        background: #fff;
        border: 1px solid var(--analytics-border);
        border-radius: 24px;
        padding: 22px;
        box-shadow: 0 12px 35px rgba(128, 0, 0, 0.06);
        margin-bottom: 20px;
    }

    .analytics-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }

    .analytics-panel-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--analytics-text);
        margin: 0;
    }

    .analytics-panel-subtitle {
        color: var(--analytics-muted);
        font-size: 13px;
        margin-top: 4px;
    }

    .analytics-two-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(320px, .65fr);
        gap: 18px;
    }

    .scope-switcher {
        display: inline-flex;
        padding: 5px;
        background: #fff4f4;
        border: 1px solid #eed7d7;
        border-radius: 999px;
        gap: 4px;
        flex-wrap: wrap;
    }

    .scope-switcher a {
        padding: 8px 12px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 800;
        text-decoration: none;
        color: #7e6464;
    }

    .scope-switcher a.active {
        background: var(--analytics-maroon);
        color: #fff;
        box-shadow: 0 8px 18px rgba(128,0,0,.16);
    }

    .respondent-list {
        display: grid;
        gap: 12px;
    }

    .respondent-card {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        border: 1px solid #f0dddd;
        border-radius: 18px;
        padding: 14px;
        background: #fffafa;
    }

    .respondent-name {
        font-weight: 900;
        color: var(--analytics-text);
    }

    .respondent-meta {
        color: var(--analytics-muted);
        font-size: 13px;
        margin-top: 4px;
    }

    .status-table {
        display: grid;
        gap: 12px;
    }

    .status-row {
        display: grid;
        grid-template-columns: 150px minmax(0,1fr);
        gap: 12px;
        align-items: start;
        border-bottom: 1px solid #f1e4e4;
        padding-bottom: 12px;
    }

    .status-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .status-key {
        font-size: 12px;
        color: var(--analytics-muted);
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    .status-value {
        color: var(--analytics-text);
        font-weight: 700;
    }

    .question-card {
        background: #fff;
        border: 1px solid var(--analytics-border);
        border-radius: 24px;
        padding: 22px;
        box-shadow: 0 10px 30px rgba(128, 0, 0, 0.055);
        margin-bottom: 18px;
    }

    .question-head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
        margin-bottom: 16px;
        border-bottom: 1px solid #f2e5e5;
        padding-bottom: 16px;
    }

    .question-number {
        width: 36px;
        height: 36px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--analytics-soft);
        color: var(--analytics-maroon);
        font-weight: 900;
        flex: 0 0 36px;
    }

    .question-title-wrap {
        display: flex;
        gap: 12px;
        align-items: flex-start;
    }

    .question-title {
        font-weight: 900;
        font-size: 16px;
        color: var(--analytics-text);
        line-height: 1.45;
    }

    .question-meta {
        color: var(--analytics-muted);
        font-size: 13px;
        margin-top: 6px;
    }

    .answer-bar-row {
        margin-bottom: 14px;
    }

    .answer-bar-top {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        font-size: 13px;
        margin-bottom: 8px;
    }

    .answer-label {
        font-weight: 800;
        color: var(--analytics-text);
    }

    .answer-count {
        color: var(--analytics-muted);
        white-space: nowrap;
        font-weight: 700;
    }

    .answer-track {
        height: 12px;
        background: #f8eeee;
        border-radius: 999px;
        overflow: hidden;
    }

    .answer-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--analytics-maroon), #b01717);
        border-radius: 999px;
    }

    .text-answer-list {
        display: grid;
        gap: 12px;
    }

    .text-answer-card {
        background: #fffafa;
        border: 1px solid #f0dddd;
        border-radius: 18px;
        padding: 14px;
    }

    .text-answer-by {
        font-weight: 900;
        color: var(--analytics-text);
    }

    .text-answer-body {
        color: #594747;
        line-height: 1.6;
        margin-top: 8px;
        font-size: 14px;
    }

    .empty-state {
        text-align: center;
        padding: 38px 20px;
        border: 1px dashed #e7caca;
        border-radius: 22px;
        background: #fffafa;
    }

    .empty-icon {
        width: 54px;
        height: 54px;
        border-radius: 18px;
        background: var(--analytics-soft);
        color: var(--analytics-maroon);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-bottom: 12px;
    }

    @media (max-width: 1100px) {
        .analytics-hero-grid,
        .analytics-two-grid {
            grid-template-columns: 1fr;
        }

        .analytics-grid-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .analytics-hero,
        .analytics-panel,
        .question-card {
            border-radius: 20px;
            padding: 18px;
        }

        .analytics-grid-stats {
            grid-template-columns: 1fr;
        }

        .respondent-card,
        .question-head,
        .analytics-panel-header {
            flex-direction: column;
        }

        .status-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="analytics-hero fade-in">
    <div class="analytics-hero-grid">
        <div>
            <span class="analytics-eyebrow">Survey Analytics</span>
            <h1 class="analytics-title"><?= e($survey['title']) ?></h1>
            <div class="analytics-subtitle">
                Analyze participation, answer distribution, and response patterns for this <?= $isTracer ? 'tracer study' : 'general survey' ?>.
            </div>

            <div class="analytics-badges">
                <span class="analytics-badge dark"><?= $isTracer ? 'Tracer Study' : 'General Survey' ?></span>
                <span class="analytics-badge soft"><?= (int)$survey['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                <?php if ($isTracer): ?>
                    <span class="analytics-badge soft"><?= $currentOnly ? 'Current latest status only' : 'All career history' ?></span>
                <?php endif; ?>
            </div>

            <div class="analytics-actions">
                <a href="survey_responses.php?id=<?= (int)$survey['id'] ?>" class="analytics-btn-light">View Responses</a>
                <?php if ($isTracer): ?>
                    <a href="tracer_dashboard.php" class="analytics-btn-outline">Tracer Dashboard</a>
                    <a href="export_tracer_report.php" class="analytics-btn-outline">Export CSV</a>
                <?php endif; ?>
                <a href="surveys.php" class="analytics-btn-outline">Back to Surveys</a>
            </div>
        </div>

        <div class="analytics-rate-card">
            <div class="analytics-rate-label">Participation Rate</div>
            <div class="analytics-rate-value"><?= (int)$responseRate ?>%</div>
            <div class="analytics-rate-bar">
                <div class="analytics-rate-fill" style="width: <?= min(100, max(0, (int)$responseRate)) ?>%;"></div>
            </div>
            <div class="analytics-muted-light">
                <?= (int)$uniqueParticipants ?> unique participant(s) out of <?= (int)$totalAlumni ?> registered alumni account(s).
            </div>
        </div>
    </div>
</div>

<?php if ($isTracer): ?>
    <div class="analytics-panel fade-in">
        <div class="analytics-panel-header">
            <div>
                <h2 class="analytics-panel-title">Analytics Scope</h2>
                <div class="analytics-panel-subtitle">
                    Current mode avoids double-counting alumni who submitted multiple career updates.
                </div>
            </div>
            <div class="scope-switcher">
                <a href="<?= e(scopeUrl($survey_id, 'current')) ?>" class="<?= $scope === 'current' ? 'active' : '' ?>">Current Latest</a>
                <a href="<?= e(scopeUrl($survey_id, 'all')) ?>" class="<?= $scope === 'all' ? 'active' : '' ?>">All History</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="analytics-grid-stats fade-in">
    <div class="analytics-stat">
        <div class="analytics-stat-label">Total Alumni</div>
        <div class="analytics-stat-value"><?= (int)$totalAlumni ?></div>
        <div class="analytics-stat-note">Registered alumni accounts</div>
    </div>

    <div class="analytics-stat">
        <div class="analytics-stat-label"><?= $isTracer && $currentOnly ? 'Current Records' : 'Total Responses' ?></div>
        <div class="analytics-stat-value"><?= (int)$totalResponses ?></div>
        <div class="analytics-stat-note"><?= $isTracer && $currentOnly ? 'Latest response per alumni' : 'Submitted entries' ?></div>
    </div>

    <div class="analytics-stat">
        <div class="analytics-stat-label">Unique Participants</div>
        <div class="analytics-stat-value"><?= (int)$uniqueParticipants ?></div>
        <div class="analytics-stat-note">Alumni who submitted</div>
    </div>

    <div class="analytics-stat">
        <div class="analytics-stat-label">Questions</div>
        <div class="analytics-stat-value"><?= count($questions) ?></div>
        <div class="analytics-stat-note"><?= (int)$requiredQuestions ?> required question(s)</div>
    </div>
</div>

<?php if ($isTracer): ?>
    <div class="analytics-grid-stats fade-in">
        <div class="analytics-stat">
            <div class="analytics-stat-label">Dominant Status</div>
            <div class="analytics-stat-value" style="font-size:22px; line-height:1.2;">
                <?= $dominantEmployment ? e($dominantEmployment['label']) : '—' ?>
            </div>
            <div class="analytics-stat-note">
                <?= $dominantEmployment ? (int)$dominantEmployment['count'] . ' response(s)' : 'No employment data yet' ?>
            </div>
        </div>

        <div class="analytics-stat">
            <div class="analytics-stat-label">Further Studies</div>
            <div class="analytics-stat-value"><?= (int)$furtherStudiesCount ?></div>
            <div class="analytics-stat-note">Marked yes, if available</div>
        </div>

        <div class="analytics-stat">
            <div class="analytics-stat-label">Recent Updates</div>
            <div class="analytics-stat-value"><?= (int)$recentUpdates ?></div>
            <div class="analytics-stat-note">Submitted in the last 30 days</div>
        </div>

        <div class="analytics-stat">
            <div class="analytics-stat-label">Latest Submission</div>
            <div class="analytics-stat-value" style="font-size:22px; line-height:1.2;">
                <?= $latestSubmission ? date('M j, Y', strtotime($latestSubmission)) : '—' ?>
            </div>
            <div class="analytics-stat-note">Most recent response date</div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($survey['description'])): ?>
    <div class="analytics-panel fade-in">
        <div class="analytics-panel-header">
            <div>
                <h2 class="analytics-panel-title">Survey Description</h2>
                <div class="analytics-panel-subtitle">Context shown to respondents.</div>
            </div>
        </div>
        <div style="color:#5c4b4b; line-height:1.75;"><?= nl2br(e($survey['description'])) ?></div>
    </div>
<?php endif; ?>

<div class="analytics-two-grid fade-in">
    <div class="analytics-panel">
        <div class="analytics-panel-header">
            <div>
                <h2 class="analytics-panel-title">Recent Respondents</h2>
                <div class="analytics-panel-subtitle">Latest submissions included in the selected analytics scope.</div>
            </div>
        </div>

        <?php if (empty($recentRespondents)): ?>
            <div class="empty-state">
                <div class="empty-icon">⌁</div>
                <div class="analytics-panel-title">No responses yet</div>
                <div class="analytics-panel-subtitle">Responses will appear here once alumni submit this survey.</div>
            </div>
        <?php else: ?>
            <div class="respondent-list">
                <?php foreach ($recentRespondents as $person): ?>
                    <div class="respondent-card">
                        <div>
                            <div class="respondent-name"><?= e(trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''))) ?></div>
                            <div class="respondent-meta"><?= e($person['email'] ?? '') ?></div>
                            <div class="respondent-meta">
                                <?= e($person['course'] ?? '—') ?><?= !empty($person['graduation_year']) ? ' • Batch ' . e($person['graduation_year']) : '' ?>
                            </div>
                        </div>
                        <div style="text-align:right; min-width:130px;">
                            <?php if ($isTracer && (int)($person['is_latest'] ?? 0) === 1): ?>
                                <span class="analytics-badge dark">Latest</span>
                            <?php endif; ?>
                            <div class="respondent-meta" style="margin-top:8px;">
                                <?= !empty($person['submitted_at']) ? date('M j, Y', strtotime($person['submitted_at'])) : '—' ?>
                            </div>
                            <a href="<?= $isTracer ? 'view_tracer_response.php?id=' . (int)$person['response_id'] : 'survey_responses.php?id=' . (int)$survey_id ?>" class="analytics-btn-primary" style="padding:8px 11px; font-size:12px; margin-top:10px;">
                                <?= $isTracer ? 'View' : 'Responses' ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="analytics-panel">
        <div class="analytics-panel-header">
            <div>
                <h2 class="analytics-panel-title">Survey Details</h2>
                <div class="analytics-panel-subtitle">Configuration and analytics summary.</div>
            </div>
        </div>

        <div class="status-table">
            <div class="status-row">
                <div class="status-key">Type</div>
                <div class="status-value"><?= $isTracer ? 'Tracer Study' : 'General Survey' ?></div>
            </div>
            <div class="status-row">
                <div class="status-key">Status</div>
                <div class="status-value"><?= (int)$survey['is_active'] === 1 ? 'Active' : 'Inactive' ?></div>
            </div>
            <div class="status-row">
                <div class="status-key">Created On</div>
                <div class="status-value"><?= !empty($survey['created_at']) ? date('F j, Y', strtotime($survey['created_at'])) : '—' ?></div>
            </div>
            <div class="status-row">
                <div class="status-key">Scope</div>
                <div class="status-value"><?= $isTracer ? ($currentOnly ? 'Current latest career records' : 'All career update history') : 'All survey responses' ?></div>
            </div>
            <div class="status-row">
                <div class="status-key">Answered Inputs</div>
                <div class="status-value"><?= (int)$totalAnsweredInputs ?></div>
            </div>
        </div>
    </div>
</div>

<div class="analytics-panel fade-in">
    <div class="analytics-panel-header">
        <div>
            <h2 class="analytics-panel-title">Question-Level Analytics</h2>
            <div class="analytics-panel-subtitle">
                Choice questions show answer distribution. Text questions show recent written responses.
            </div>
        </div>
    </div>
</div>

<?php if (empty($questionAnalytics)): ?>
    <div class="analytics-panel">
        <div class="empty-state">
            <div class="empty-icon">?</div>
            <div class="analytics-panel-title">No questions found</div>
            <div class="analytics-panel-subtitle">Add questions to this survey before reviewing analytics.</div>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($questionAnalytics as $index => $item): ?>
    <?php
        $question = $item['question'];
        $answeredCount = (int)$item['answered'];
    ?>
    <div class="question-card fade-in">
        <div class="question-head">
            <div class="question-title-wrap">
                <div class="question-number"><?= $index + 1 ?></div>
                <div>
                    <div class="question-title"><?= e($question['question_text']) ?></div>
                    <div class="question-meta">
                        <?= e(ucfirst((string)$question['question_type'])) ?> question •
                        <?= (int)($question['is_required'] ?? 0) === 1 ? 'Required' : 'Optional' ?> •
                        <?= $answeredCount ?> answered
                    </div>
                </div>
            </div>
            <span class="analytics-badge soft"><?= $item['type'] === 'choice' ? 'Distribution' : 'Text Responses' ?></span>
        </div>

        <?php if ($item['type'] === 'choice'): ?>
            <?php if (empty($item['data'])): ?>
                <div class="empty-state">
                    <div class="empty-icon">!</div>
                    <div class="analytics-panel-title">No options found</div>
                    <div class="analytics-panel-subtitle">This choice question has no saved options yet.</div>
                </div>
            <?php else: ?>
                <?php foreach ($item['data'] as $row): ?>
                    <?php
                        $denominator = max(1, $answeredCount);
                        $percentage = round(((int)$row['count'] / $denominator) * 100);
                    ?>
                    <div class="answer-bar-row">
                        <div class="answer-bar-top">
                            <span class="answer-label"><?= e($row['label']) ?></span>
                            <span class="answer-count"><?= (int)$row['count'] ?> response(s) • <?= (int)$percentage ?>%</span>
                        </div>
                        <div class="answer-track">
                            <div class="answer-fill" style="width: <?= min(100, max(0, (int)$percentage)) ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php else: ?>
            <?php if (empty($item['data'])): ?>
                <div class="empty-state">
                    <div class="empty-icon">⌁</div>
                    <div class="analytics-panel-title">No text responses yet</div>
                    <div class="analytics-panel-subtitle">Written responses will appear here after alumni answer this question.</div>
                </div>
            <?php else: ?>
                <div class="text-answer-list">
                    <?php foreach ($item['data'] as $answer): ?>
                        <div class="text-answer-card">
                            <div class="text-answer-by">
                                <?= e(trim(($answer['first_name'] ?? '') . ' ' . ($answer['last_name'] ?? ''))) ?>
                            </div>
                            <div class="respondent-meta">
                                <?= !empty($answer['submitted_at']) ? date('M j, Y', strtotime($answer['submitted_at'])) : '' ?>
                            </div>
                            <div class="text-answer-body">
                                <?= nl2br(e($answer['answer_text'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="analytics-panel-subtitle" style="margin-top:12px;">Showing up to 12 recent text responses.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php require_once '../includes/dashboard_footer.php'; ?>
