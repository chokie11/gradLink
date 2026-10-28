<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];
$errors = [];

function clean_answer_value($value) {
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $clean[] = $item;
            }
        }
        return implode(', ', $clean);
    }

    return trim((string)$value);
}

function old_career_answer($qid) {
    $answers = $_POST['answers'] ?? [];
    $value = $answers[$qid] ?? '';

    if (is_array($value)) {
        return implode(', ', array_filter(array_map('trim', array_map('strval', $value))));
    }

    return trim((string)$value);
}

// Get the latest active tracer survey created by admin.
$surveyStmt = $pdo->prepare("
    SELECT *
    FROM tracer_surveys
    WHERE survey_type = 'tracer'
      AND is_active = 1
    ORDER BY id DESC
    LIMIT 1
");
$surveyStmt->execute();
$survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

$questions = [];
$optionsMap = [];
$hasPreviousTracerResponse = false;
$previousTracerCount = 0;
$latestCareerUpdate = null;

if ($survey) {
    $previousStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tracer_responses r
        INNER JOIN tracer_surveys s ON s.id = r.survey_id
        WHERE r.alumni_id = ?
          AND s.survey_type = 'tracer'
    ");
    $previousStmt->execute([$user_id]);
    $previousTracerCount = (int)$previousStmt->fetchColumn();
    $hasPreviousTracerResponse = $previousTracerCount > 0;

    $latestStmt = $pdo->prepare("
        SELECT submitted_at
        FROM tracer_responses r
        INNER JOIN tracer_surveys s ON s.id = r.survey_id
        WHERE r.alumni_id = ?
          AND s.survey_type = 'tracer'
        ORDER BY r.submitted_at DESC, r.id DESC
        LIMIT 1
    ");
    $latestStmt->execute([$user_id]);
    $latestCareerUpdate = $latestStmt->fetchColumn();

    $questionsStmt = $pdo->prepare("
        SELECT *
        FROM tracer_questions
        WHERE survey_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $questionsStmt->execute([$survey['id']]);
    $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($questions as $question) {
        if (in_array($question['question_type'], ['radio', 'select', 'checkbox'], true)) {
            $optionStmt = $pdo->prepare("
                SELECT option_text
                FROM tracer_question_options
                WHERE question_id = ?
                ORDER BY id ASC
            ");
            $optionStmt->execute([$question['id']]);
            $optionsMap[$question['id']] = $optionStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$survey) {
        $errors[] = 'No active tracer survey is available right now.';
    } elseif (empty($questions)) {
        $errors[] = 'The active tracer survey has no questions yet.';
    } else {
        $answers = $_POST['answers'] ?? [];

        foreach ($questions as $question) {
            $qid = (int)$question['id'];
            $answerText = clean_answer_value($answers[$qid] ?? '');

            if ((int)$question['is_required'] === 1 && $answerText === '') {
                $errors[] = 'Please answer all required questions.';
                break;
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Mark previous tracer responses for this alumni as not latest.
                $clearLatestStmt = $pdo->prepare("
                    UPDATE tracer_responses
                    SET is_latest = 0
                    WHERE alumni_id = ?
                      AND survey_id IN (
                          SELECT id
                          FROM tracer_surveys
                          WHERE survey_type = 'tracer'
                      )
                ");
                $clearLatestStmt->execute([$user_id]);

                // Save this submission as the newest career snapshot.
                $responseStmt = $pdo->prepare("
                    INSERT INTO tracer_responses (survey_id, alumni_id, survey_version, is_latest)
                    VALUES (?, ?, ?, 1)
                ");
                $responseStmt->execute([
                    $survey['id'],
                    $user_id,
                    $survey['version'] ?? 1
                ]);
                $responseId = $pdo->lastInsertId();

                foreach ($questions as $question) {
                    $qid = (int)$question['id'];
                    $answerText = clean_answer_value($answers[$qid] ?? '');

                    $answerStmt = $pdo->prepare("
                        INSERT INTO tracer_answers (response_id, question_id, answer_text)
                        VALUES (?, ?, ?)
                    ");
                    $answerStmt->execute([
                        $responseId,
                        $qid,
                        $answerText !== '' ? $answerText : null
                    ]);
                }

                $pdo->commit();
                header('Location: career_timeline.php?updated=1');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Something went wrong while saving your career update.';
            }
        }
    }
}

$totalQuestions = count($questions);
$requiredCount = 0;
foreach ($questions as $question) {
    if ((int)$question['is_required'] === 1) {
        $requiredCount++;
    }
}
$estimatedMinutes = max(1, (int)ceil($totalQuestions * 0.5));

$pageTitle = $hasPreviousTracerResponse ? 'Update Career Status' : 'Take Tracer Survey';
$pageSubtitle = $hasPreviousTracerResponse
    ? 'Submit your latest employment, education, and career information.'
    : 'Complete your first tracer study form.';
$activePage = 'surveys';

require_once '../includes/dashboard_header.php';
?>

<style>
    .career-shell {
        display: grid;
        gap: 22px;
    }

    .career-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 30px;
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.26), transparent 34%),
            linear-gradient(135deg, #800000 0%, #5f0000 55%, #3b0000 100%);
        color: #ffffff;
        box-shadow: 0 20px 45px rgba(128, 0, 0, 0.20);
    }

    .career-hero::after {
        content: "";
        position: absolute;
        width: 260px;
        height: 260px;
        right: -90px;
        bottom: -130px;
        border-radius: 50%;
        border: 42px solid rgba(255, 255, 255, 0.08);
    }

    .career-hero-content {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 22px;
        align-items: center;
    }

    .career-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 13px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.20);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.7px;
        text-transform: uppercase;
    }

    .career-title-main {
        margin-top: 14px;
        margin-bottom: 8px;
        font-size: clamp(28px, 4vw, 42px);
        line-height: 1.05;
        font-weight: 900;
        letter-spacing: -1px;
    }

    .career-description {
        color: rgba(255, 255, 255, 0.84);
        max-width: 780px;
        line-height: 1.7;
        margin: 0;
    }

    .career-hero-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }

    .career-back-btn,
    .career-light-btn {
        color: #800000;
        background: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.7);
        border-radius: 14px;
        padding: 11px 16px;
        font-weight: 800;
        text-decoration: none;
        box-shadow: 0 14px 28px rgba(0, 0, 0, 0.14);
        transition: 0.2s ease;
        white-space: nowrap;
    }

    .career-light-btn {
        background: rgba(255, 255, 255, 0.14);
        color: #ffffff;
        border-color: rgba(255, 255, 255, 0.25);
    }

    .career-back-btn:hover,
    .career-light-btn:hover {
        transform: translateY(-2px);
    }

    .career-back-btn:hover {
        color: #5f0000;
    }

    .career-light-btn:hover {
        color: #ffffff;
        background: rgba(255, 255, 255, 0.20);
    }

    .career-metrics {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }

    .career-metric-card {
        background: #ffffff;
        border: 1px solid #f0dddd;
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 14px 35px rgba(76, 25, 25, 0.07);
    }

    .metric-label {
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #8b7575;
        margin-bottom: 5px;
    }

    .metric-value {
        font-size: 26px;
        font-weight: 900;
        color: #431111;
        line-height: 1.1;
    }

    .metric-note {
        margin-top: 7px;
        color: #8a7777;
        font-size: 13px;
    }

    .career-form-card {
        background: #ffffff;
        border: 1px solid #f0dddd;
        border-radius: 28px;
        box-shadow: 0 18px 45px rgba(76, 25, 25, 0.08);
        overflow: hidden;
    }

    .career-form-topbar {
        padding: 20px 24px;
        border-bottom: 1px solid #f2e4e4;
        background: linear-gradient(135deg, #fffafa, #ffffff);
        display: flex;
        justify-content: space-between;
        gap: 14px;
        align-items: center;
        flex-wrap: wrap;
    }

    .form-top-title {
        font-size: 18px;
        font-weight: 900;
        color: #341111;
        margin: 0;
    }

    .form-top-subtitle {
        color: #8a7777;
        margin: 4px 0 0;
        font-size: 14px;
    }

    .progress-pill {
        min-width: 180px;
        padding: 10px 12px;
        border-radius: 16px;
        background: #fff1f1;
        border: 1px solid #efd0d0;
    }

    .progress-label-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        font-size: 12px;
        font-weight: 800;
        color: #800000;
        margin-bottom: 7px;
    }

    .career-progress-track {
        height: 8px;
        border-radius: 999px;
        background: #f0d8d8;
        overflow: hidden;
    }

    .career-progress-bar {
        width: 0%;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #800000, #b91c1c);
        transition: width 0.25s ease;
    }

    .career-form-body {
        padding: 24px;
    }

    .career-alert {
        border: 1px solid #f3b7b7;
        background: #fff2f2;
        color: #711414;
        border-radius: 18px;
        padding: 14px 16px;
        margin-bottom: 18px;
    }

    .career-info-alert {
        border: 1px solid #efd0d0;
        background: linear-gradient(135deg, #fff8f8, #ffffff);
        color: #5b1b1b;
        border-radius: 18px;
        padding: 15px 16px;
        margin-bottom: 18px;
    }

    .question-card {
        position: relative;
        border: 1px solid #f0dddd;
        border-radius: 22px;
        padding: 20px;
        background: #ffffff;
        box-shadow: 0 10px 25px rgba(76, 25, 25, 0.045);
        margin-bottom: 16px;
        transition: 0.2s ease;
    }

    .question-card:focus-within {
        border-color: #d5a0a0;
        box-shadow: 0 14px 35px rgba(128, 0, 0, 0.10);
        transform: translateY(-1px);
    }

    .question-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }

    .question-number {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.7px;
        color: #800000;
        background: #fff1f1;
        border: 1px solid #efd0d0;
        padding: 7px 10px;
        border-radius: 999px;
    }

    .required-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 12px;
        font-weight: 800;
        color: #8b1a1a;
        background: #fff4f4;
        border: 1px solid #f1cdcd;
        white-space: nowrap;
    }

    .optional-badge {
        color: #7f7272;
        background: #f8f4f4;
        border-color: #eee2e2;
    }

    .question-text {
        font-size: 17px;
        font-weight: 800;
        color: #2f1515;
        margin: 0 0 12px;
        line-height: 1.45;
    }

    .career-input,
    .career-select,
    .career-textarea {
        width: 100%;
        border: 1px solid #eadada;
        border-radius: 15px;
        padding: 13px 14px;
        font-size: 15px;
        color: #321515;
        background: #fffdfd;
        transition: 0.2s ease;
    }

    .career-input:focus,
    .career-select:focus,
    .career-textarea:focus {
        outline: none;
        border-color: #9f3333;
        box-shadow: 0 0 0 4px rgba(128, 0, 0, 0.10);
        background: #ffffff;
    }

    .career-textarea {
        resize: vertical;
        min-height: 118px;
    }

    .option-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .option-card {
        position: relative;
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 50px;
        padding: 12px 13px;
        border-radius: 15px;
        border: 1px solid #eadada;
        background: #fffdfd;
        color: #351717;
        cursor: pointer;
        transition: 0.2s ease;
        font-weight: 700;
    }

    .option-card:hover {
        border-color: #d8a5a5;
        background: #fff6f6;
        transform: translateY(-1px);
    }

    .option-card input {
        width: 18px;
        height: 18px;
        accent-color: #800000;
        flex: 0 0 auto;
    }

    .option-card:has(input:checked) {
        border-color: #800000;
        background: linear-gradient(135deg, #fff4f4, #ffffff);
        box-shadow: 0 10px 24px rgba(128, 0, 0, 0.10);
    }

    .submit-panel {
        margin-top: 22px;
        padding: 20px;
        border-radius: 22px;
        background: linear-gradient(135deg, #fff8f8, #ffffff);
        border: 1px solid #f0dddd;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }

    .submit-title {
        color: #321515;
        font-weight: 900;
        margin-bottom: 3px;
    }

    .submit-note {
        color: #8a7777;
        font-size: 14px;
    }

    .btn-submit-career {
        border: none;
        border-radius: 16px;
        padding: 13px 20px;
        font-weight: 900;
        color: #ffffff;
        background: linear-gradient(135deg, #800000, #5f0000);
        box-shadow: 0 14px 28px rgba(128, 0, 0, 0.20);
        transition: 0.2s ease;
    }

    .btn-submit-career:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 34px rgba(128, 0, 0, 0.25);
    }

    .empty-career-state {
        text-align: center;
        padding: 50px 20px;
        color: #7f7272;
    }

    .empty-career-icon {
        width: 72px;
        height: 72px;
        border-radius: 24px;
        background: #fff1f1;
        color: #800000;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 30px;
        margin-bottom: 14px;
    }

    @media (max-width: 1000px) {
        .career-metrics {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 900px) {
        .career-hero-content {
            grid-template-columns: 1fr;
        }

        .career-hero-actions {
            justify-content: flex-start;
        }

        .option-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 576px) {
        .career-hero,
        .career-form-body {
            padding: 20px;
        }

        .career-form-topbar {
            padding: 18px;
        }

        .question-card {
            padding: 17px;
        }

        .career-metrics {
            grid-template-columns: 1fr;
        }

        .submit-panel {
            align-items: stretch;
        }

        .btn-submit-career {
            width: 100%;
        }
    }
</style>

<div class="career-shell fade-in">
    <div class="career-hero">
        <div class="career-hero-content">
            <div>
                <span class="career-kicker">
                    <?= $hasPreviousTracerResponse ? 'Career Update' : 'Tracer Study' ?>
                </span>

                <h1 class="career-title-main">
                    <?= $hasPreviousTracerResponse ? 'Update Career Status' : 'Take Tracer Survey' ?>
                </h1>

                <p class="career-description">
                    <?php if ($hasPreviousTracerResponse): ?>
                        Submit your latest career information. Your previous records will stay saved in your career timeline.
                    <?php else: ?>
                        Complete your first tracer study form. This will become your first career snapshot in the alumni tracking system.
                    <?php endif; ?>
                </p>
            </div>

            <div class="career-hero-actions">
                <a href="career_timeline.php" class="career-back-btn">View Career Timeline</a>
                <a href="surveys.php" class="career-light-btn">Back to Surveys</a>
            </div>
        </div>
    </div>

    <div class="career-metrics">
        <div class="career-metric-card">
            <div class="metric-label">Status</div>
            <div class="metric-value"><?= $hasPreviousTracerResponse ? 'Update' : 'First Time' ?></div>
            <div class="metric-note"><?= $hasPreviousTracerResponse ? 'Add a new career snapshot' : 'Start your career timeline' ?></div>
        </div>

        <div class="career-metric-card">
            <div class="metric-label">Questions</div>
            <div class="metric-value"><?= $totalQuestions ?></div>
            <div class="metric-note">Total items to answer</div>
        </div>

        <div class="career-metric-card">
            <div class="metric-label">Required</div>
            <div class="metric-value"><?= $requiredCount ?></div>
            <div class="metric-note">Required questions</div>
        </div>

        <div class="career-metric-card">
            <div class="metric-label">Last Update</div>
            <div class="metric-value"><?= $latestCareerUpdate ? date('M j', strtotime($latestCareerUpdate)) : 'None' ?></div>
            <div class="metric-note"><?= $latestCareerUpdate ? date('Y', strtotime($latestCareerUpdate)) : 'No career record yet' ?></div>
        </div>
    </div>

    <div class="career-form-card">
        <div class="career-form-topbar">
            <div>
                <h2 class="form-top-title">
                    <?= $survey ? htmlspecialchars($survey['title']) : 'Career Tracking Form' ?>
                </h2>
                <p class="form-top-subtitle">
                    <?= $hasPreviousTracerResponse
                        ? 'Fields marked as required must be completed before saving your new career update.'
                        : 'Fields marked as required must be completed before submitting your first tracer survey.' ?>
                </p>
            </div>

            <div class="progress-pill">
                <div class="progress-label-row">
                    <span>Progress</span>
                    <span id="careerProgressText">0%</span>
                </div>
                <div class="career-progress-track">
                    <div class="career-progress-bar" id="careerProgressBar"></div>
                </div>
            </div>
        </div>

        <div class="career-form-body">
            <?php if (!$survey): ?>
                <div class="empty-career-state">
                    <div class="empty-career-icon">!</div>
                    <h3>No active tracer survey</h3>
                    <p>No active tracer survey is available right now. Please contact the administrator.</p>
                    <a href="surveys.php" class="career-back-btn">Return to Surveys</a>
                </div>
            <?php else: ?>
                <?php if (!empty($survey['description'])): ?>
                    <div class="career-info-alert">
                        <?= nl2br(htmlspecialchars($survey['description'])) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="career-alert">
                        <strong>Please check your answers.</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (empty($questions)): ?>
                    <div class="empty-career-state">
                        <div class="empty-career-icon">?</div>
                        <h3>No questions available</h3>
                        <p>The active tracer survey has no questions yet. Please contact the administrator.</p>
                        <a href="surveys.php" class="career-back-btn">Return to Surveys</a>
                    </div>
                <?php else: ?>
                    <form method="POST" action="" id="careerForm">
                        <?php foreach ($questions as $index => $question): ?>
                            <?php
                                $qid = (int)$question['id'];
                                $type = $question['question_type'];
                                $isRequired = (int)$question['is_required'] === 1;
                                $currentAnswer = old_career_answer($qid);
                                $currentAnswerParts = array_map('trim', explode(',', $currentAnswer));
                            ?>

                            <div class="question-card" data-question-card data-required="<?= $isRequired ? '1' : '0' ?>">
                                <div class="question-head">
                                    <span class="question-number">Question <?= $index + 1 ?></span>
                                    <span class="required-badge <?= $isRequired ? '' : 'optional-badge' ?>">
                                        <?= $isRequired ? 'Required' : 'Optional' ?>
                                    </span>
                                </div>

                                <p class="question-text"><?= htmlspecialchars($question['question_text']) ?></p>

                                <?php if ($type === 'text'): ?>
                                    <input
                                        type="text"
                                        name="answers[<?= $qid ?>]"
                                        class="career-input career-answer-input"
                                        value="<?= htmlspecialchars($currentAnswer) ?>"
                                        placeholder="Type your answer here"
                                        <?= $isRequired ? 'required' : '' ?>
                                    >

                                <?php elseif ($type === 'textarea'): ?>
                                    <textarea
                                        name="answers[<?= $qid ?>]"
                                        class="career-textarea career-answer-input"
                                        rows="4"
                                        placeholder="Write your answer here"
                                        <?= $isRequired ? 'required' : '' ?>
                                    ><?= htmlspecialchars($currentAnswer) ?></textarea>

                                <?php elseif ($type === 'radio'): ?>
                                    <div class="option-grid career-answer-group">
                                        <?php foreach ($optionsMap[$qid] ?? [] as $option): ?>
                                            <?php $optionId = 'q' . $qid . '_' . md5($option); ?>
                                            <label class="option-card" for="<?= htmlspecialchars($optionId) ?>">
                                                <input
                                                    type="radio"
                                                    name="answers[<?= $qid ?>]"
                                                    value="<?= htmlspecialchars($option) ?>"
                                                    id="<?= htmlspecialchars($optionId) ?>"
                                                    class="career-answer-input"
                                                    <?= ($currentAnswer === $option) ? 'checked' : '' ?>
                                                    <?= $isRequired ? 'required' : '' ?>
                                                >
                                                <span><?= htmlspecialchars($option) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                <?php elseif ($type === 'checkbox'): ?>
                                    <div class="option-grid career-answer-group">
                                        <?php foreach ($optionsMap[$qid] ?? [] as $option): ?>
                                            <?php $optionId = 'q' . $qid . '_' . md5($option); ?>
                                            <label class="option-card" for="<?= htmlspecialchars($optionId) ?>">
                                                <input
                                                    type="checkbox"
                                                    name="answers[<?= $qid ?>][]"
                                                    value="<?= htmlspecialchars($option) ?>"
                                                    id="<?= htmlspecialchars($optionId) ?>"
                                                    class="career-answer-input"
                                                    <?= in_array($option, $currentAnswerParts, true) ? 'checked' : '' ?>
                                                    <?= $isRequired ? 'required' : '' ?>
                                                >
                                                <span><?= htmlspecialchars($option) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                <?php elseif ($type === 'select'): ?>
                                    <select
                                        name="answers[<?= $qid ?>]"
                                        class="career-select career-answer-input"
                                        <?= $isRequired ? 'required' : '' ?>
                                    >
                                        <option value="">Select an option</option>
                                        <?php foreach ($optionsMap[$qid] ?? [] as $option): ?>
                                            <option value="<?= htmlspecialchars($option) ?>" <?= ($currentAnswer === $option) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                <?php else: ?>
                                    <input
                                        type="text"
                                        name="answers[<?= $qid ?>]"
                                        class="career-input career-answer-input"
                                        value="<?= htmlspecialchars($currentAnswer) ?>"
                                        placeholder="Type your answer here"
                                        <?= $isRequired ? 'required' : '' ?>
                                    >
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="submit-panel">
                            <div>
                                <div class="submit-title">
                                    <?= $hasPreviousTracerResponse ? 'Ready to save your update?' : 'Ready to submit your tracer survey?' ?>
                                </div>
                                <div class="submit-note">
                                    <?= $hasPreviousTracerResponse
                                        ? 'This will create a new career timeline record without deleting your previous submissions.'
                                        : 'This will become your first career timeline record.' ?>
                                </div>
                            </div>
                            <button type="submit" class="btn-submit-career">
                                <?= $hasPreviousTracerResponse ? 'Save Career Update' : 'Submit Tracer Survey' ?>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('careerForm');
    if (!form) return;

    const progressText = document.getElementById('careerProgressText');
    const progressBar = document.getElementById('careerProgressBar');
    const cards = Array.from(document.querySelectorAll('[data-question-card]'));

    function hasAnswer(card) {
        const inputs = Array.from(card.querySelectorAll('.career-answer-input'));
        if (inputs.length === 0) return false;

        const choiceInputs = inputs.filter(input => input.type === 'radio' || input.type === 'checkbox');
        if (choiceInputs.length > 0) {
            return choiceInputs.some(input => input.checked);
        }

        return inputs.some(input => String(input.value || '').trim() !== '');
    }

    function updateProgress() {
        if (cards.length === 0) return;

        const answered = cards.filter(hasAnswer).length;
        const percent = Math.round((answered / cards.length) * 100);

        progressText.textContent = percent + '%';
        progressBar.style.width = percent + '%';
    }

    form.addEventListener('input', updateProgress);
    form.addEventListener('change', updateProgress);
    updateProgress();
})();
</script>

<?php require_once '../includes/dashboard_footer.php'; ?>
