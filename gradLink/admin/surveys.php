<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatDateSafe($date, $format = 'M j, Y')
{
    if (empty($date)) {
        return '—';
    }

    return date($format, strtotime($date));
}

$typeFilter = trim($_GET['type'] ?? 'all');
$statusFilter = trim($_GET['status'] ?? 'all');
$search = trim($_GET['q'] ?? '');

$surveyStmt = $pdo->query("
    SELECT
        ts.*,
        COALESCE(ts.survey_type, 'general') AS survey_type,
        COUNT(DISTINCT tq.id) AS total_questions,
        COUNT(DISTINCT tr.id) AS total_responses,
        COUNT(DISTINCT tr.alumni_id) AS total_participants,
        MAX(tr.submitted_at) AS latest_response_at
    FROM tracer_surveys ts
    LEFT JOIN tracer_questions tq ON ts.id = tq.survey_id
    LEFT JOIN tracer_responses tr ON ts.id = tr.survey_id
    GROUP BY ts.id
    ORDER BY ts.created_at DESC, ts.id DESC
");
$allSurveys = $surveyStmt->fetchAll(PDO::FETCH_ASSOC);

$filteredSurveys = array_values(array_filter($allSurveys, function ($survey) use ($typeFilter, $statusFilter, $search) {
    $surveyType = $survey['survey_type'] ?: 'general';
    $isActive = (int) ($survey['is_active'] ?? 0) === 1;

    if ($typeFilter !== 'all' && $surveyType !== $typeFilter) {
        return false;
    }

    if ($statusFilter === 'active' && !$isActive) {
        return false;
    }

    if ($statusFilter === 'inactive' && $isActive) {
        return false;
    }

    if ($search !== '') {
        $haystack = strtolower(($survey['title'] ?? '') . ' ' . ($survey['description'] ?? '') . ' ' . $surveyType);
        if (strpos($haystack, strtolower($search)) === false) {
            return false;
        }
    }

    return true;
}));

$totalSurveys = count($allSurveys);
$activeSurveys = count(array_filter($allSurveys, fn($row) => (int) ($row['is_active'] ?? 0) === 1));
$tracerSurveys = count(array_filter($allSurveys, fn($row) => ($row['survey_type'] ?? 'general') === 'tracer'));
$generalSurveys = count(array_filter($allSurveys, fn($row) => ($row['survey_type'] ?? 'general') !== 'tracer'));
$totalQuestions = array_sum(array_map(fn($row) => (int) ($row['total_questions'] ?? 0), $allSurveys));
$totalResponses = array_sum(array_map(fn($row) => (int) ($row['total_responses'] ?? 0), $allSurveys));
$activeTracerSurveys = array_values(array_filter($allSurveys, function ($row) {
    return ($row['survey_type'] ?? 'general') === 'tracer' && (int) ($row['is_active'] ?? 0) === 1;
}));
$activeTracerSurvey = $activeTracerSurveys[0] ?? null;
$multipleActiveTracer = count($activeTracerSurveys) > 1;

$pageTitle = 'Survey Management';
$pageSubtitle = 'Create, organize, and monitor general surveys and tracer studies.';
$activePage = 'surveys';

require_once '../includes/dashboard_header.php';
?>

<style>
    .survey-modern-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 30px;
        margin-bottom: 24px;
        color: #fff;
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.22), transparent 32%),
            linear-gradient(135deg, var(--maroon), var(--dark-maroon));
        box-shadow: 0 18px 45px rgba(128, 0, 0, 0.18);
    }

    .survey-modern-hero::after {
        content: '';
        position: absolute;
        width: 240px;
        height: 240px;
        right: -70px;
        bottom: -110px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.10);
    }

    .survey-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1.5fr) minmax(260px, .75fr);
        gap: 24px;
        align-items: center;
    }

    .survey-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.18);
        font-weight: 800;
        font-size: 12px;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .survey-hero-title {
        margin: 16px 0 8px;
        font-size: clamp(28px, 4vw, 44px);
        line-height: 1.05;
        font-weight: 900;
        letter-spacing: -1.2px;
    }

    .survey-hero-text {
        max-width: 760px;
        margin: 0;
        color: rgba(255, 255, 255, .84);
        font-size: 15px;
        line-height: 1.7;
    }

    .survey-hero-panel {
        border-radius: 22px;
        padding: 18px;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.18);
        backdrop-filter: blur(8px);
    }

    .survey-hero-panel .label {
        color: rgba(255, 255, 255, .72);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
        font-weight: 800;
    }

    .survey-hero-panel .value {
        font-size: 22px;
        font-weight: 900;
        margin: 6px 0;
    }

    .survey-action-row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 20px;
    }

    .survey-btn-white,
    .survey-btn-ghost,
    .survey-btn-maroon,
    .survey-btn-soft,
    .survey-btn-danger-soft {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 999px;
        padding: 10px 16px;
        font-weight: 800;
        font-size: 13px;
        text-decoration: none;
        transition: all .2s ease;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .survey-btn-white {
        background: #fff;
        color: var(--maroon);
        box-shadow: 0 12px 28px rgba(0, 0, 0, .12);
    }

    .survey-btn-white:hover {
        transform: translateY(-1px);
        color: var(--maroon);
    }

    .survey-btn-ghost {
        color: #fff;
        background: rgba(255, 255, 255, .12);
        border-color: rgba(255, 255, 255, .24);
    }

    .survey-btn-ghost:hover {
        color: #fff;
        background: rgba(255, 255, 255, .18);
        transform: translateY(-1px);
    }

    .survey-btn-maroon {
        color: #fff;
        background: linear-gradient(135deg, var(--maroon), var(--dark-maroon));
        box-shadow: 0 12px 24px rgba(128, 0, 0, .18);
    }

    .survey-btn-maroon:hover {
        color: #fff;
        transform: translateY(-1px);
    }

    .survey-btn-soft {
        color: var(--maroon);
        background: #fff5f5;
        border-color: #f1dada;
    }

    .survey-btn-soft:hover {
        color: var(--maroon);
        background: #ffecec;
        transform: translateY(-1px);
    }

    .survey-btn-danger-soft {
        color: #a01818;
        background: #fff1f1;
        border-color: #f4cccc;
    }

    .survey-btn-danger-soft:hover {
        color: #8a1010;
        background: #ffe3e3;
        transform: translateY(-1px);
    }

    .survey-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 22px;
    }

    .survey-stat-card {
        background: #fff;
        border: 1px solid var(--border-light);
        border-radius: 22px;
        padding: 20px;
        box-shadow: 0 10px 28px rgba(128, 0, 0, .06);
        position: relative;
        overflow: hidden;
    }

    .survey-stat-card::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 80px;
        height: 80px;
        background: radial-gradient(circle, rgba(128, 0, 0, .10), transparent 70%);
    }

    .survey-stat-label {
        font-size: 12px;
        color: #7d6b6b;
        font-weight: 900;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .survey-stat-value {
        font-size: 30px;
        font-weight: 900;
        color: #2b2020;
        line-height: 1;
        margin: 10px 0 6px;
    }

    .survey-stat-note {
        color: #847373;
        font-size: 13px;
    }

    .survey-toolbar {
        background: #fff;
        border: 1px solid var(--border-light);
        border-radius: 24px;
        padding: 18px;
        box-shadow: 0 10px 28px rgba(128, 0, 0, .06);
        margin-bottom: 22px;
    }

    .survey-toolbar-grid {
        display: grid;
        grid-template-columns: minmax(220px, 1fr) 170px 170px auto;
        gap: 12px;
        align-items: end;
    }

    .survey-form-label {
        font-size: 12px;
        font-weight: 900;
        color: #6d5555;
        text-transform: uppercase;
        letter-spacing: .07em;
        margin-bottom: 6px;
    }

    .survey-input,
    .survey-select {
        width: 100%;
        border: 1px solid #eadede;
        border-radius: 14px;
        padding: 11px 13px;
        color: #2d2424;
        outline: none;
        background: #fff;
        transition: all .2s ease;
    }

    .survey-input:focus,
    .survey-select:focus {
        border-color: rgba(128, 0, 0, .35);
        box-shadow: 0 0 0 4px rgba(128, 0, 0, .08);
    }

    .survey-alert {
        border-radius: 22px;
        padding: 18px;
        margin-bottom: 22px;
        border: 1px solid #f0dada;
        background: #fff7f7;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
    }

    .survey-alert strong {
        color: var(--maroon);
    }

    .survey-card-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }

    .survey-card {
        background: #fff;
        border: 1px solid var(--border-light);
        border-radius: 24px;
        box-shadow: 0 10px 28px rgba(128, 0, 0, .06);
        overflow: hidden;
        transition: all .2s ease;
        display: flex;
        flex-direction: column;
        min-height: 100%;
    }

    .survey-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 16px 38px rgba(128, 0, 0, .10);
    }

    .survey-card-top {
        padding: 20px 20px 16px;
        border-bottom: 1px solid #f0e5e5;
    }

    .survey-card-title-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
    }

    .survey-card-title {
        font-size: 18px;
        font-weight: 900;
        color: #2d2222;
        margin: 0;
        line-height: 1.25;
    }

    .survey-card-meta {
        color: #887373;
        font-size: 13px;
        margin-top: 8px;
    }

    .survey-description {
        margin-top: 12px;
        color: #6d5c5c;
        line-height: 1.65;
        font-size: 14px;
    }

    .survey-description.empty {
        color: #a38f8f;
        font-style: italic;
    }

    .survey-badge-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }

    .survey-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        border: 1px solid transparent;
    }

    .badge-tracer {
        color: #7a0000;
        background: #fff0f0;
        border-color: #f0d3d3;
    }

    .badge-general {
        color: #4b5563;
        background: #f4f6f8;
        border-color: #e2e8f0;
    }

    .badge-active {
        color: #146c43;
        background: #eaf8ef;
        border-color: #cdeed9;
    }

    .badge-inactive {
        color: #7c6f64;
        background: #f8f4ef;
        border-color: #eadfd1;
    }

    .badge-featured {
        color: #fff;
        background: linear-gradient(135deg, var(--maroon), var(--dark-maroon));
        border-color: rgba(128, 0, 0, .15);
    }

    .survey-card-body {
        padding: 18px 20px;
        flex: 1;
    }

    .survey-mini-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }

    .survey-mini-stat {
        border-radius: 16px;
        background: #fff8f8;
        border: 1px solid #f0e2e2;
        padding: 12px;
    }

    .survey-mini-stat .num {
        color: #2d2222;
        font-size: 18px;
        font-weight: 900;
        line-height: 1;
    }

    .survey-mini-stat .label {
        color: #8a7474;
        font-size: 12px;
        margin-top: 5px;
        font-weight: 700;
    }

    .survey-card-actions {
        padding: 16px 20px 20px;
        display: flex;
        flex-wrap: wrap;
        gap: 9px;
        border-top: 1px solid #f0e5e5;
    }

    .survey-empty {
        background: #fff;
        border: 1px dashed #e5cdcd;
        border-radius: 24px;
        padding: 40px 24px;
        text-align: center;
        box-shadow: 0 10px 28px rgba(128, 0, 0, .05);
    }

    .survey-empty-icon {
        width: 64px;
        height: 64px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 22px;
        background: #fff2f2;
        color: var(--maroon);
        font-size: 26px;
        margin-bottom: 14px;
    }

    @media (max-width: 1100px) {
        .survey-stat-grid,
        .survey-card-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .survey-toolbar-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    @media (max-width: 760px) {
        .survey-hero-grid,
        .survey-stat-grid,
        .survey-card-grid,
        .survey-toolbar-grid,
        .survey-mini-stats {
            grid-template-columns: 1fr;
        }

        .survey-modern-hero {
            padding: 24px;
        }

        .survey-card-title-row {
            flex-direction: column;
        }
    }
</style>

<div class="survey-modern-hero">
    <div class="survey-hero-grid">
        <div>
            <span class="survey-eyebrow">Survey Control Center</span>
            <h2 class="survey-hero-title">Manage surveys and tracer studies with clarity.</h2>
            <p class="survey-hero-text">
                Create general surveys, maintain the official DepEd tracer study form, monitor responses, and keep alumni career tracking organized from one admin workspace.
            </p>

            <div class="survey-action-row">
                <a href="create_survey.php" class="survey-btn-white">+ Create Survey</a>
                <a href="tracer_dashboard.php" class="survey-btn-ghost">Tracer Dashboard</a>
            </div>
        </div>

        <div class="survey-hero-panel">
            <div class="label">Active Tracer Study</div>
            <?php if ($activeTracerSurvey): ?>
                <div class="value"><?= h($activeTracerSurvey['title']) ?></div>
                <div style="color: rgba(255,255,255,.78); font-size: 13px; line-height: 1.55;">
                    <?= (int) $activeTracerSurvey['total_responses'] ?> total response<?= (int) $activeTracerSurvey['total_responses'] === 1 ? '' : 's' ?>
                    · <?= (int) $activeTracerSurvey['total_questions'] ?> question<?= (int) $activeTracerSurvey['total_questions'] === 1 ? '' : 's' ?>
                </div>
                <div class="survey-action-row" style="margin-top: 14px;">
                    <a href="edit_survey.php?id=<?= (int) $activeTracerSurvey['id'] ?>" class="survey-btn-ghost">Edit Active Form</a>
                </div>
            <?php else: ?>
                <div class="value">No active tracer</div>
                <div style="color: rgba(255,255,255,.78); font-size: 13px; line-height: 1.55;">
                    Create or activate one tracer study so alumni can take the tracer survey and update career status.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($multipleActiveTracer): ?>
    <div class="survey-alert">
        <div>
            <strong>Multiple active tracer surveys detected.</strong>
            <div class="text-muted mt-1">
                Alumni will use the latest active tracer survey, but it is better to keep only one active tracer study. Edit the older tracer surveys and set them inactive.
            </div>
        </div>
        <a href="?type=tracer&status=active" class="survey-btn-soft">Review Active Tracers</a>
    </div>
<?php endif; ?>

<div class="survey-stat-grid">
    <div class="survey-stat-card">
        <div class="survey-stat-label">Total Surveys</div>
        <div class="survey-stat-value"><?= (int) $totalSurveys ?></div>
        <div class="survey-stat-note"><?= (int) $generalSurveys ?> general · <?= (int) $tracerSurveys ?> tracer</div>
    </div>

    <div class="survey-stat-card">
        <div class="survey-stat-label">Active Surveys</div>
        <div class="survey-stat-value"><?= (int) $activeSurveys ?></div>
        <div class="survey-stat-note">Available or visible to alumni</div>
    </div>

    <div class="survey-stat-card">
        <div class="survey-stat-label">Total Questions</div>
        <div class="survey-stat-value"><?= (int) $totalQuestions ?></div>
        <div class="survey-stat-note">Across all saved survey forms</div>
    </div>

    <div class="survey-stat-card">
        <div class="survey-stat-label">Total Responses</div>
        <div class="survey-stat-value"><?= (int) $totalResponses ?></div>
        <div class="survey-stat-note">General surveys and tracer updates</div>
    </div>
</div>

<div class="survey-toolbar">
    <form method="GET" class="survey-toolbar-grid">
        <div>
            <div class="survey-form-label">Search</div>
            <input type="text" name="q" class="survey-input" value="<?= h($search) ?>" placeholder="Search by title, description, or type">
        </div>

        <div>
            <div class="survey-form-label">Survey Type</div>
            <select name="type" class="survey-select">
                <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All types</option>
                <option value="tracer" <?= $typeFilter === 'tracer' ? 'selected' : '' ?>>Tracer Study</option>
                <option value="general" <?= $typeFilter === 'general' ? 'selected' : '' ?>>General Survey</option>
            </select>
        </div>

        <div>
            <div class="survey-form-label">Status</div>
            <select name="status" class="survey-select">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All status</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="survey-btn-maroon" style="border:0;">Apply</button>
            <a href="surveys.php" class="survey-btn-soft">Reset</a>
        </div>
    </form>
</div>

<?php if (empty($filteredSurveys)): ?>
    <div class="survey-empty">
        <div class="survey-empty-icon">◌</div>
        <h3 style="font-weight: 900; margin-bottom: 8px;">No surveys found</h3>
        <p class="text-muted mb-4">
            <?= empty($allSurveys) ? 'Start by creating your first survey or tracer study.' : 'Try adjusting your search or filter options.' ?>
        </p>
        <a href="create_survey.php" class="survey-btn-maroon">Create Survey</a>
    </div>
<?php else: ?>
    <div class="survey-card-grid">
        <?php foreach ($filteredSurveys as $survey): ?>
            <?php
            $surveyId = (int) $survey['id'];
            $surveyType = ($survey['survey_type'] ?? 'general') === 'tracer' ? 'tracer' : 'general';
            $isTracer = $surveyType === 'tracer';
            $isActive = (int) ($survey['is_active'] ?? 0) === 1;
            $isActiveTracer = $isTracer && $isActive && $activeTracerSurvey && (int) $activeTracerSurvey['id'] === $surveyId;
            $createdAt = formatDateSafe($survey['created_at'] ?? null);
            $latestResponse = formatDateSafe($survey['latest_response_at'] ?? null);
            ?>

            <article class="survey-card">
                <div class="survey-card-top">
                    <div class="survey-card-title-row">
                        <div>
                            <h3 class="survey-card-title"><?= h($survey['title']) ?></h3>
                            <div class="survey-card-meta">Created <?= h($createdAt) ?></div>
                        </div>

                        <?php if ($isActiveTracer): ?>
                            <span class="survey-badge badge-featured">Official Tracer</span>
                        <?php endif; ?>
                    </div>

                    <div class="survey-badge-row">
                        <span class="survey-badge <?= $isTracer ? 'badge-tracer' : 'badge-general' ?>">
                            <?= $isTracer ? 'Tracer Study' : 'General Survey' ?>
                        </span>
                        <span class="survey-badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>">
                            <?= $isActive ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>

                    <?php if (!empty($survey['description'])): ?>
                        <div class="survey-description">
                            <?= nl2br(h($survey['description'])) ?>
                        </div>
                    <?php else: ?>
                        <div class="survey-description empty">No description provided for this survey.</div>
                    <?php endif; ?>
                </div>

                <div class="survey-card-body">
                    <div class="survey-mini-stats">
                        <div class="survey-mini-stat">
                            <div class="num"><?= (int) $survey['total_questions'] ?></div>
                            <div class="label">Questions</div>
                        </div>
                        <div class="survey-mini-stat">
                            <div class="num"><?= (int) $survey['total_responses'] ?></div>
                            <div class="label">Responses</div>
                        </div>
                        <div class="survey-mini-stat">
                            <div class="num"><?= (int) $survey['total_participants'] ?></div>
                            <div class="label">Participants</div>
                        </div>
                    </div>

                    <div class="mt-3 small text-muted">
                        <strong>Latest response:</strong> <?= h($latestResponse) ?>
                    </div>
                </div>

                <div class="survey-card-actions">
                    <a href="edit_survey.php?id=<?= $surveyId ?>" class="survey-btn-soft">Edit</a>
                    <a href="survey_analytics.php?id=<?= $surveyId ?>" class="survey-btn-soft">Analytics</a>
                    <a href="survey_responses.php?id=<?= $surveyId ?>" class="survey-btn-soft">Responses</a>

                    <?php if ($isTracer): ?>
                        <a href="tracer_dashboard.php" class="survey-btn-maroon">Tracer Dashboard</a>
                    <?php endif; ?>

                    <a href="delete_survey.php?id=<?= $surveyId ?>" class="survey-btn-danger-soft"
                        onclick="return confirm('Are you sure you want to delete this survey? This action cannot be undone.');">
                        Delete
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once '../includes/dashboard_footer.php'; ?>
