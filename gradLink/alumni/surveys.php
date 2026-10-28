<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Active Surveys
|--------------------------------------------------------------------------
| General surveys use answer_survey.php.
| Tracer surveys use update_career.php because tracer/career tracking allows
| multiple submissions over time for the alumni career timeline.
*/
$stmt = $pdo->prepare("
    SELECT
        ts.*,
        COALESCE(q.total_questions, 0) AS total_questions,
        COALESCE(r.response_count, 0) AS response_count,
        r.latest_response_at
    FROM tracer_surveys ts
    LEFT JOIN (
        SELECT survey_id, COUNT(*) AS total_questions
        FROM tracer_questions
        GROUP BY survey_id
    ) q ON q.survey_id = ts.id
    LEFT JOIN (
        SELECT survey_id, alumni_id, COUNT(*) AS response_count, MAX(submitted_at) AS latest_response_at
        FROM tracer_responses
        WHERE alumni_id = ?
        GROUP BY survey_id, alumni_id
    ) r ON r.survey_id = ts.id AND r.alumni_id = ?
    WHERE ts.is_active = 1
    ORDER BY
        CASE WHEN COALESCE(ts.survey_type, 'general') = 'tracer' THEN 0 ELSE 1 END,
        ts.created_at DESC,
        ts.id DESC
");
$stmt->execute([$user_id, $user_id]);
$surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalSurveys = count($surveys);
$pendingCount = 0;
$completedCount = 0;
$tracerCount = 0;
$generalCount = 0;

foreach ($surveys as $survey) {
    $surveyType = $survey['survey_type'] ?? 'general';
    $responseCount = (int) ($survey['response_count'] ?? 0);

    if ($surveyType === 'tracer') {
        $tracerCount++;
    } else {
        $generalCount++;
    }

    if ($responseCount > 0) {
        $completedCount++;
    } else {
        $pendingCount++;
    }
}

$pageTitle = 'Surveys';
$pageSubtitle = 'Complete active surveys and keep your alumni record updated.';
$activePage = 'surveys';

require_once '../includes/dashboard_header.php';
?>

<style>
    .surveys-shell {
        display: grid;
        gap: 22px;
    }

    .surveys-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 30px;
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.18), transparent 34%),
            linear-gradient(135deg, #7b1113 0%, #8f171a 44%, #4f090a 100%);
        color: #ffffff;
        box-shadow: 0 22px 55px rgba(128, 0, 0, 0.20);
    }

    .surveys-hero::after {
        content: "";
        position: absolute;
        right: -70px;
        bottom: -90px;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.10);
    }

    .surveys-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1.6fr) minmax(260px, 0.8fr);
        gap: 24px;
        align-items: center;
    }

    .surveys-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.20);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .surveys-title {
        margin: 16px 0 10px;
        font-size: clamp(28px, 4vw, 44px);
        line-height: 1.05;
        font-weight: 900;
        letter-spacing: -1.2px;
    }

    .surveys-text {
        max-width: 700px;
        color: rgba(255, 255, 255, 0.82);
        font-size: 15px;
        line-height: 1.7;
        margin-bottom: 20px;
    }

    .surveys-hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }

    .surveys-btn-white,
    .surveys-btn-ghost,
    .surveys-btn-maroon,
    .surveys-btn-soft {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 14px;
        padding: 11px 16px;
        font-weight: 800;
        font-size: 14px;
        text-decoration: none;
        border: 1px solid transparent;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .surveys-btn-white {
        background: #ffffff;
        color: #7b1113;
        box-shadow: 0 12px 26px rgba(0, 0, 0, 0.16);
    }

    .surveys-btn-white:hover {
        transform: translateY(-2px);
        color: #7b1113;
    }

    .surveys-btn-ghost {
        color: #ffffff;
        background: rgba(255, 255, 255, 0.12);
        border-color: rgba(255, 255, 255, 0.22);
    }

    .surveys-btn-ghost:hover {
        background: rgba(255, 255, 255, 0.18);
        color: #ffffff;
    }

    .surveys-btn-maroon {
        background: #7b1113;
        color: #ffffff;
        box-shadow: 0 12px 22px rgba(128, 0, 0, 0.18);
    }

    .surveys-btn-maroon:hover {
        background: #5f0c0e;
        color: #ffffff;
        transform: translateY(-1px);
    }

    .surveys-btn-soft {
        background: #fff4f4;
        color: #7b1113;
        border-color: #f0d7d7;
    }

    .surveys-btn-soft:hover {
        background: #ffe9e9;
        color: #7b1113;
    }

    .surveys-hero-summary {
        background: rgba(255, 255, 255, 0.13);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 24px;
        padding: 20px;
        backdrop-filter: blur(10px);
    }

    .summary-label {
        color: rgba(255, 255, 255, 0.70);
        font-size: 13px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .summary-value {
        margin-top: 6px;
        font-size: 42px;
        line-height: 1;
        font-weight: 900;
    }

    .summary-note {
        color: rgba(255, 255, 255, 0.78);
        margin-top: 8px;
        font-size: 14px;
        line-height: 1.6;
    }

    .survey-stats-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
    }

    .survey-stat-card {
        background: #ffffff;
        border: 1px solid #f0e1e1;
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 14px 35px rgba(86, 24, 24, 0.07);
    }

    .survey-stat-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .survey-stat-icon {
        width: 42px;
        height: 42px;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fff4f4;
        color: #7b1113;
        font-size: 18px;
        font-weight: 900;
    }

    .survey-stat-label {
        color: #776868;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.6px;
    }

    .survey-stat-value {
        margin-top: 10px;
        color: #231818;
        font-size: 28px;
        font-weight: 900;
    }

    .survey-stat-note {
        color: #8a7777;
        font-size: 13px;
        margin-top: 2px;
    }

    .survey-panel {
        background: #ffffff;
        border: 1px solid #f0e1e1;
        border-radius: 26px;
        padding: 22px;
        box-shadow: 0 18px 42px rgba(86, 24, 24, 0.07);
    }

    .survey-panel-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        margin-bottom: 18px;
    }

    .survey-panel-title {
        color: #241717;
        font-size: 22px;
        font-weight: 900;
        letter-spacing: -0.4px;
        margin: 0;
    }

    .survey-panel-subtitle {
        color: #796b6b;
        font-size: 14px;
        margin-top: 5px;
    }

    .survey-filter-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: flex-end;
    }

    .survey-filter-btn {
        border: 1px solid #ecdada;
        background: #ffffff;
        color: #6c5656;
        border-radius: 999px;
        padding: 8px 12px;
        font-size: 13px;
        font-weight: 800;
        transition: all 0.2s ease;
    }

    .survey-filter-btn:hover,
    .survey-filter-btn.active {
        background: #7b1113;
        color: #ffffff;
        border-color: #7b1113;
    }

    .survey-card-grid {
        display: grid;
        gap: 16px;
    }

    .survey-card {
        position: relative;
        overflow: hidden;
        background: #ffffff;
        border: 1px solid #f0e1e1;
        border-radius: 22px;
        padding: 20px;
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .survey-card::before {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 5px;
        background: #7b1113;
    }

    .survey-card.tracer::before {
        background: linear-gradient(180deg, #7b1113, #c89b3c);
    }

    .survey-card:hover {
        transform: translateY(-2px);
        border-color: #e6caca;
        box-shadow: 0 16px 36px rgba(86, 24, 24, 0.10);
    }

    .survey-card-head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
    }

    .survey-card-title {
        color: #241717;
        font-size: 18px;
        line-height: 1.35;
        font-weight: 900;
        margin: 0;
    }

    .survey-meta-row {
        margin-top: 10px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .survey-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 7px 10px;
        background: #f8f3f3;
        color: #6f5d5d;
        border: 1px solid #efe3e3;
        font-size: 12px;
        font-weight: 800;
    }

    .survey-pill.maroon {
        background: #fff3f3;
        color: #7b1113;
        border-color: #ebcccc;
    }

    .survey-pill.success {
        background: #f0fff5;
        color: #187145;
        border-color: #c8ead5;
    }

    .survey-pill.warning {
        background: #fff8e8;
        color: #9b6511;
        border-color: #eedaa9;
    }

    .survey-description {
        margin-top: 14px;
        color: #695959;
        font-size: 14px;
        line-height: 1.7;
    }

    .survey-card-footer {
        margin-top: 18px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
    }

    .survey-small-note {
        color: #8a7777;
        font-size: 13px;
    }

    .survey-empty-state {
        text-align: center;
        border: 1px dashed #e7cccc;
        border-radius: 24px;
        padding: 44px 20px;
        background: #fffafa;
    }

    .survey-empty-icon {
        width: 68px;
        height: 68px;
        margin: 0 auto 14px;
        border-radius: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fff1f1;
        color: #7b1113;
        font-size: 28px;
        font-weight: 900;
    }

    .survey-empty-title {
        color: #241717;
        font-size: 20px;
        font-weight: 900;
        margin-bottom: 6px;
    }

    .survey-empty-text {
        color: #7e6c6c;
        max-width: 520px;
        margin: 0 auto;
        line-height: 1.7;
    }

    .survey-hidden {
        display: none !important;
    }

    @media (max-width: 992px) {
        .surveys-hero-grid,
        .survey-stats-grid {
            grid-template-columns: 1fr 1fr;
        }

        .survey-panel-head {
            flex-direction: column;
        }

        .survey-filter-wrap {
            justify-content: flex-start;
        }
    }

    @media (max-width: 640px) {
        .surveys-hero {
            padding: 22px;
            border-radius: 22px;
        }

        .surveys-hero-grid,
        .survey-stats-grid {
            grid-template-columns: 1fr;
        }

        .survey-card-head,
        .survey-card-footer {
            flex-direction: column;
            align-items: flex-start;
        }

        .surveys-hero-actions,
        .surveys-btn-white,
        .surveys-btn-ghost,
        .surveys-btn-maroon,
        .surveys-btn-soft {
            width: 100%;
        }
    }
</style>

<div class="surveys-shell fade-in">
    <section class="surveys-hero">
        <div class="surveys-hero-grid">
            <div>
                <span class="surveys-eyebrow">Alumni Surveys</span>
                <h1 class="surveys-title">Stay connected through surveys and career tracking.</h1>
                <p class="surveys-text">
                    Answer active surveys from the institution and keep your tracer records updated. Your responses help improve programs, alumni services, and career support.
                </p>

                <div class="surveys-hero-actions">
                    <a href="update_career.php" class="surveys-btn-white">Career Tracker</a>
                    <a href="career_timeline.php" class="surveys-btn-ghost">View Career Timeline</a>
                </div>
            </div>

            <div class="surveys-hero-summary">
                <div class="summary-label">Active Surveys</div>
                <div class="summary-value"><?= (int) $totalSurveys ?></div>
                <div class="summary-note">
                    <?= $pendingCount > 0 ? (int) $pendingCount . ' item(s) need your attention.' : 'You are currently up to date.' ?>
                </div>
            </div>
        </div>
    </section>

    <section class="survey-stats-grid">
        <div class="survey-stat-card">
            <div class="survey-stat-top">
                <div class="survey-stat-label">Available</div>
                <div class="survey-stat-icon">S</div>
            </div>
            <div class="survey-stat-value"><?= (int) $totalSurveys ?></div>
            <div class="survey-stat-note">Active surveys now</div>
        </div>

        <div class="survey-stat-card">
            <div class="survey-stat-top">
                <div class="survey-stat-label">Pending</div>
                <div class="survey-stat-icon">!</div>
            </div>
            <div class="survey-stat-value"><?= (int) $pendingCount ?></div>
            <div class="survey-stat-note">Not yet answered</div>
        </div>

        <div class="survey-stat-card">
            <div class="survey-stat-top">
                <div class="survey-stat-label">Completed</div>
                <div class="survey-stat-icon">✓</div>
            </div>
            <div class="survey-stat-value"><?= (int) $completedCount ?></div>
            <div class="survey-stat-note">Already submitted</div>
        </div>

        <div class="survey-stat-card">
            <div class="survey-stat-top">
                <div class="survey-stat-label">Tracer</div>
                <div class="survey-stat-icon">T</div>
            </div>
            <div class="survey-stat-value"><?= (int) $tracerCount ?></div>
            <div class="survey-stat-note">Career tracking form</div>
        </div>
    </section>

    <section class="survey-panel">
        <div class="survey-panel-head">
            <div>
                <h2 class="survey-panel-title">Survey Center</h2>
                <div class="survey-panel-subtitle">Review active surveys, track your submissions, and continue career updates.</div>
            </div>

            <div class="survey-filter-wrap" aria-label="Survey filters">
                <button type="button" class="survey-filter-btn active" data-filter="all">All</button>
                <button type="button" class="survey-filter-btn" data-filter="pending">Pending</button>
                <button type="button" class="survey-filter-btn" data-filter="completed">Completed</button>
                <button type="button" class="survey-filter-btn" data-filter="tracer">Tracer</button>
            </div>
        </div>

        <?php if (empty($surveys)): ?>
            <div class="survey-empty-state">
                <div class="survey-empty-icon">S</div>
                <div class="survey-empty-title">No active surveys available</div>
                <p class="survey-empty-text">
                    There are no active surveys published for alumni right now. Please check again later for institutional surveys or tracer study updates.
                </p>
            </div>
        <?php else: ?>
            <div class="survey-card-grid" id="surveyCardGrid">
                <?php foreach ($surveys as $survey): ?>
                    <?php
                        $surveyType = $survey['survey_type'] ?? 'general';
                        $responseCount = (int) ($survey['response_count'] ?? 0);
                        $isAnswered = $responseCount > 0;
                        $isTracer = $surveyType === 'tracer';
                        $statusFilter = $isAnswered ? 'completed' : 'pending';
                        $createdAt = !empty($survey['created_at']) ? date('M j, Y', strtotime($survey['created_at'])) : 'Recently posted';
                        $latestResponse = !empty($survey['latest_response_at']) ? date('M j, Y', strtotime($survey['latest_response_at'])) : null;
                    ?>

                    <article class="survey-card <?= $isTracer ? 'tracer' : '' ?>"
                             data-status="<?= htmlspecialchars($statusFilter) ?>"
                             data-type="<?= htmlspecialchars($isTracer ? 'tracer' : 'general') ?>">
                        <div class="survey-card-head">
                            <div>
                                <h3 class="survey-card-title"><?= htmlspecialchars($survey['title']) ?></h3>

                                <div class="survey-meta-row">
                                    <span class="survey-pill maroon">
                                        <?= $isTracer ? 'Tracer Study' : 'General Survey' ?>
                                    </span>
                                    <span class="survey-pill">
                                        <?= (int) $survey['total_questions'] ?> question<?= (int) $survey['total_questions'] === 1 ? '' : 's' ?>
                                    </span>
                                    <span class="survey-pill">
                                        Posted <?= htmlspecialchars($createdAt) ?>
                                    </span>
                                </div>
                            </div>

                            <?php if ($isAnswered): ?>
                                <span class="survey-pill success">Submitted</span>
                            <?php else: ?>
                                <span class="survey-pill warning">Action Needed</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($survey['description'])): ?>
                            <div class="survey-description">
                                <?= nl2br(htmlspecialchars($survey['description'])) ?>
                            </div>
                        <?php else: ?>
                            <div class="survey-description">
                                No description was provided. Please open the form to review the questions and instructions.
                            </div>
                        <?php endif; ?>

                        <div class="survey-card-footer">
                            <div class="survey-small-note">
                                <?php if ($isTracer && $isAnswered): ?>
                                    Last career update: <?= htmlspecialchars($latestResponse ?? 'Submitted') ?>
                                <?php elseif ($isAnswered): ?>
                                    Submitted<?= $latestResponse ? ' on ' . htmlspecialchars($latestResponse) : '' ?>
                                <?php elseif ($isTracer): ?>
                                    Complete your first tracer survey to start your career timeline.
                                <?php else: ?>
                                    This survey is still pending.
                                <?php endif; ?>
                            </div>

                            <div class="d-flex gap-2 flex-wrap">
                                <?php if ($isTracer): ?>
                                    <a href="update_career.php" class="surveys-btn-maroon">
                                        <?= $isAnswered ? 'Update Career Status' : 'Take Tracer Survey' ?>
                                    </a>
                                    <a href="career_timeline.php" class="surveys-btn-soft">Timeline</a>
                                <?php elseif ($isAnswered): ?>
                                    <span class="surveys-btn-soft" aria-disabled="true">Already Submitted</span>
                                <?php else: ?>
                                    <a href="answer_survey.php?id=<?= (int) $survey['id'] ?>" class="surveys-btn-maroon">Answer Survey</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="survey-empty-state survey-hidden" id="surveyFilterEmpty">
                <div class="survey-empty-icon">?</div>
                <div class="survey-empty-title">No surveys match this filter</div>
                <p class="survey-empty-text">Try choosing another filter to see available surveys.</p>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const filterButtons = document.querySelectorAll('.survey-filter-btn');
        const surveyCards = document.querySelectorAll('.survey-card');
        const emptyState = document.getElementById('surveyFilterEmpty');

        filterButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const filter = button.dataset.filter;
                let visibleCount = 0;

                filterButtons.forEach(function (btn) {
                    btn.classList.remove('active');
                });
                button.classList.add('active');

                surveyCards.forEach(function (card) {
                    const status = card.dataset.status;
                    const type = card.dataset.type;
                    const shouldShow =
                        filter === 'all' ||
                        filter === status ||
                        filter === type;

                    if (shouldShow) {
                        card.classList.remove('survey-hidden');
                        visibleCount++;
                    } else {
                        card.classList.add('survey-hidden');
                    }
                });

                if (emptyState) {
                    emptyState.classList.toggle('survey-hidden', visibleCount > 0);
                }
            });
        });
    });
</script>

<?php require_once '../includes/dashboard_footer.php'; ?>
