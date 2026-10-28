<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];
$survey_id = $_GET['id'] ?? ($_POST['survey_id'] ?? null);

if (!$survey_id || !ctype_digit((string)$survey_id)) {
    header('Location: surveys.php');
    exit;
}

$surveyStmt = $pdo->prepare("
    SELECT *
    FROM tracer_surveys
    WHERE id = ? AND is_active = 1
    LIMIT 1
");
$surveyStmt->execute([$survey_id]);
$survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    header('Location: surveys.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| One-time survey protection
|--------------------------------------------------------------------------
| This file is for regular/general surveys. Tracer career updates are handled
| by alumni/update_career.php so alumni can submit multiple career snapshots.
*/
$checkStmt = $pdo->prepare("
    SELECT id
    FROM tracer_responses
    WHERE survey_id = ? AND alumni_id = ?
    LIMIT 1
");
$checkStmt->execute([$survey_id, $user_id]);

if ($checkStmt->fetch()) {
    header('Location: surveys.php');
    exit;
}

$questionsStmt = $pdo->prepare("
    SELECT *
    FROM tracer_questions
    WHERE survey_id = ?
    ORDER BY sort_order ASC, id ASC
");
$questionsStmt->execute([$survey_id]);
$questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

$optionsMap = [];
foreach ($questions as $question) {
    if (in_array($question['question_type'], ['radio', 'select'], true)) {
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

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $answers = $_POST['answers'] ?? [];

    foreach ($questions as $question) {
        $qid = $question['id'];
        $answerValue = $answers[$qid] ?? '';
        $answerText = is_array($answerValue) ? '' : trim((string)$answerValue);

        if ((int)$question['is_required'] === 1 && $answerText === '') {
            $errors[] = 'Please answer all required questions before submitting.';
            break;
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $responseStmt = $pdo->prepare("
                INSERT INTO tracer_responses (survey_id, alumni_id)
                VALUES (?, ?)
            ");
            $responseStmt->execute([$survey_id, $user_id]);
            $responseId = $pdo->lastInsertId();

            foreach ($questions as $question) {
                $qid = $question['id'];
                $answerValue = $answers[$qid] ?? '';
                $answerText = is_array($answerValue) ? '' : trim((string)$answerValue);

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
            header('Location: surveys.php');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while submitting the survey.';
        }
    }
}

function oldAnswer($questionId): string
{
    $value = $_POST['answers'][$questionId] ?? '';
    return is_array($value) ? '' : (string)$value;
}

$totalQuestions = count($questions);
$requiredCount = 0;
foreach ($questions as $question) {
    if ((int)$question['is_required'] === 1) {
        $requiredCount++;
    }
}
$estimatedMinutes = max(1, (int)ceil($totalQuestions * 0.5));
$surveyType = $survey['survey_type'] ?? 'general';

$pageTitle = 'Answer Survey';
$pageSubtitle = 'Complete the survey form carefully before submitting.';
$activePage = 'surveys';

require_once '../includes/dashboard_header.php';
?>

<style>
    .survey-shell {
        display: grid;
        gap: 22px;
    }

    .survey-hero {
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

    .survey-hero::after {
        content: "";
        position: absolute;
        width: 260px;
        height: 260px;
        right: -90px;
        bottom: -130px;
        border-radius: 50%;
        border: 42px solid rgba(255, 255, 255, 0.08);
    }

    .survey-hero-content {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 22px;
        align-items: center;
    }

    .survey-kicker {
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

    .survey-title-main {
        margin-top: 14px;
        margin-bottom: 8px;
        font-size: clamp(28px, 4vw, 42px);
        line-height: 1.05;
        font-weight: 900;
        letter-spacing: -1px;
    }

    .survey-description {
        color: rgba(255, 255, 255, 0.82);
        max-width: 780px;
        line-height: 1.7;
        margin: 0;
    }

    .survey-hero-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }

    .survey-back-btn {
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

    .survey-back-btn:hover {
        color: #5f0000;
        transform: translateY(-2px);
    }

    .survey-metrics {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }

    .survey-metric-card {
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
        line-height: 1;
    }

    .metric-note {
        margin-top: 7px;
        color: #8a7777;
        font-size: 13px;
    }

    .survey-form-card {
        background: #ffffff;
        border: 1px solid #f0dddd;
        border-radius: 28px;
        box-shadow: 0 18px 45px rgba(76, 25, 25, 0.08);
        overflow: hidden;
    }

    .survey-form-topbar {
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

    .survey-progress-track {
        height: 8px;
        border-radius: 999px;
        background: #f0d8d8;
        overflow: hidden;
    }

    .survey-progress-bar {
        width: 0%;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #800000, #b91c1c);
        transition: width 0.25s ease;
    }

    .survey-form-body {
        padding: 24px;
    }

    .survey-alert {
        border: 1px solid #f3b7b7;
        background: #fff2f2;
        color: #711414;
        border-radius: 18px;
        padding: 14px 16px;
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

    .survey-input,
    .survey-select,
    .survey-textarea {
        width: 100%;
        border: 1px solid #eadada;
        border-radius: 15px;
        padding: 13px 14px;
        font-size: 15px;
        color: #321515;
        background: #fffdfd;
        transition: 0.2s ease;
    }

    .survey-input:focus,
    .survey-select:focus,
    .survey-textarea:focus {
        outline: none;
        border-color: #9f3333;
        box-shadow: 0 0 0 4px rgba(128, 0, 0, 0.10);
        background: #ffffff;
    }

    .survey-textarea {
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

    .btn-submit-survey {
        border: none;
        border-radius: 16px;
        padding: 13px 20px;
        font-weight: 900;
        color: #ffffff;
        background: linear-gradient(135deg, #800000, #5f0000);
        box-shadow: 0 14px 28px rgba(128, 0, 0, 0.20);
        transition: 0.2s ease;
    }

    .btn-submit-survey:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 34px rgba(128, 0, 0, 0.25);
    }

    .empty-survey-state {
        text-align: center;
        padding: 50px 20px;
        color: #7f7272;
    }

    .empty-survey-icon {
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

    @media (max-width: 900px) {
        .survey-hero-content {
            grid-template-columns: 1fr;
        }

        .survey-hero-actions {
            justify-content: flex-start;
        }

        .survey-metrics {
            grid-template-columns: 1fr;
        }

        .option-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 576px) {
        .survey-hero,
        .survey-form-body {
            padding: 20px;
        }

        .survey-form-topbar {
            padding: 18px;
        }

        .question-card {
            padding: 17px;
        }

        .submit-panel {
            align-items: stretch;
        }

        .btn-submit-survey {
            width: 100%;
        }
    }
</style>

<div class="survey-shell fade-in">
    <div class="survey-hero">
        <div class="survey-hero-content">
            <div>
                <span class="survey-kicker">
                    <?= $surveyType === 'tracer' ? 'Tracer Study' : 'Alumni Survey' ?>
                </span>

                <h1 class="survey-title-main"><?= htmlspecialchars($survey['title']) ?></h1>

                <?php if (!empty($survey['description'])): ?>
                    <p class="survey-description"><?= nl2br(htmlspecialchars($survey['description'])) ?></p>
                <?php else: ?>
                    <p class="survey-description">
                        Please complete the survey below. Your responses help the institution improve alumni services,
                        career programs, and future opportunities.
                    </p>
                <?php endif; ?>
            </div>

            <div class="survey-hero-actions">
                <a href="surveys.php" class="survey-back-btn">Back to Surveys</a>
            </div>
        </div>
    </div>

    <div class="survey-metrics">
        <div class="survey-metric-card">
            <div class="metric-label">Questions</div>
            <div class="metric-value"><?= $totalQuestions ?></div>
            <div class="metric-note">Total items to answer</div>
        </div>

        <div class="survey-metric-card">
            <div class="metric-label">Required</div>
            <div class="metric-value"><?= $requiredCount ?></div>
            <div class="metric-note">Required questions</div>
        </div>

        <div class="survey-metric-card">
            <div class="metric-label">Estimated Time</div>
            <div class="metric-value"><?= $estimatedMinutes ?> min</div>
            <div class="metric-note">Approximate completion time</div>
        </div>
    </div>

    <div class="survey-form-card">
        <div class="survey-form-topbar">
            <div>
                <h2 class="form-top-title">Survey Form</h2>
                <p class="form-top-subtitle">Fields marked as required must be completed before submitting.</p>
            </div>

            <div class="progress-pill">
                <div class="progress-label-row">
                    <span>Progress</span>
                    <span id="surveyProgressText">0%</span>
                </div>
                <div class="survey-progress-track">
                    <div class="survey-progress-bar" id="surveyProgressBar"></div>
                </div>
            </div>
        </div>

        <div class="survey-form-body">
            <?php if (!empty($errors)): ?>
                <div class="survey-alert">
                    <strong>Please check your answers.</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($questions)): ?>
                <div class="empty-survey-state">
                    <div class="empty-survey-icon">?</div>
                    <h3>No questions available</h3>
                    <p>This survey is active, but no questions have been added yet.</p>
                    <a href="surveys.php" class="survey-back-btn">Return to Surveys</a>
                </div>
            <?php else: ?>
                <form method="POST" action="" id="surveyForm">
                    <input type="hidden" name="survey_id" value="<?= htmlspecialchars($survey_id) ?>">

                    <?php foreach ($questions as $index => $question): ?>
                        <?php
                            $qid = (int)$question['id'];
                            $type = $question['question_type'];
                            $isRequired = (int)$question['is_required'] === 1;
                            $currentAnswer = oldAnswer($qid);
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
                                    class="survey-input survey-answer-input"
                                    value="<?= htmlspecialchars($currentAnswer) ?>"
                                    placeholder="Type your answer here"
                                    <?= $isRequired ? 'required' : '' ?>
                                >

                            <?php elseif ($type === 'textarea'): ?>
                                <textarea
                                    name="answers[<?= $qid ?>]"
                                    class="survey-textarea survey-answer-input"
                                    rows="4"
                                    placeholder="Write your answer here"
                                    <?= $isRequired ? 'required' : '' ?>
                                ><?= htmlspecialchars($currentAnswer) ?></textarea>

                            <?php elseif ($type === 'radio'): ?>
                                <div class="option-grid survey-answer-group">
                                    <?php foreach ($optionsMap[$qid] ?? [] as $option): ?>
                                        <?php $optionId = 'q' . $qid . '_' . md5($option); ?>
                                        <label class="option-card" for="<?= htmlspecialchars($optionId) ?>">
                                            <input
                                                type="radio"
                                                name="answers[<?= $qid ?>]"
                                                value="<?= htmlspecialchars($option) ?>"
                                                id="<?= htmlspecialchars($optionId) ?>"
                                                class="survey-answer-input"
                                                <?= ($currentAnswer === $option) ? 'checked' : '' ?>
                                                <?= $isRequired ? 'required' : '' ?>
                                            >
                                            <span><?= htmlspecialchars($option) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($type === 'select'): ?>
                                <select
                                    name="answers[<?= $qid ?>]"
                                    class="survey-select survey-answer-input"
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
                                    class="survey-input survey-answer-input"
                                    value="<?= htmlspecialchars($currentAnswer) ?>"
                                    placeholder="Type your answer here"
                                    <?= $isRequired ? 'required' : '' ?>
                                >
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="submit-panel">
                        <div>
                            <div class="submit-title">Ready to submit?</div>
                            <div class="submit-note">Review your answers first. Once submitted, this survey cannot be answered again.</div>
                        </div>
                        <button type="submit" class="btn-submit-survey">Submit Survey</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('surveyForm');
    if (!form) return;

    const progressText = document.getElementById('surveyProgressText');
    const progressBar = document.getElementById('surveyProgressBar');
    const cards = Array.from(document.querySelectorAll('[data-question-card]'));

    function hasAnswer(card) {
        const inputs = Array.from(card.querySelectorAll('.survey-answer-input'));
        if (inputs.length === 0) return false;

        const radioInputs = inputs.filter(input => input.type === 'radio');
        if (radioInputs.length > 0) {
            return radioInputs.some(input => input.checked);
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
