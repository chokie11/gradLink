<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$survey_id = $_GET['id'] ?? null;

if (!$survey_id || !ctype_digit((string)$survey_id)) {
    header('Location: surveys.php');
    exit;
}

$surveyStmt = $pdo->prepare("
    SELECT *
    FROM tracer_surveys
    WHERE id = ?
    LIMIT 1
");
$surveyStmt->execute([$survey_id]);
$survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
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
$existingQuestions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

$questionOptionsMap = [];

foreach ($existingQuestions as $question) {
    $optionStmt = $pdo->prepare("
        SELECT option_text
        FROM tracer_question_options
        WHERE question_id = ?
        ORDER BY id ASC
    ");
    $optionStmt->execute([$question['id']]);

    $options = $optionStmt->fetchAll(PDO::FETCH_COLUMN);

    $questionOptionsMap[$question['id']] = implode("\n", array_map(function ($opt) {
        return trim(html_entity_decode($opt, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }, $options));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $question_ids = $_POST['question_ids'] ?? [];
    $questions = $_POST['questions'] ?? [];
    $types = $_POST['question_types'] ?? [];
    $requireds = $_POST['question_required'] ?? [];
    $options = $_POST['question_options'] ?? [];

    if ($title === '') {
        $errors[] = 'Survey title is required.';
    }

    $validQuestions = [];
    foreach ($questions as $index => $questionText) {
        if (is_array($questionText)) {
            $questionText = reset($questionText) ?: '';
        }

        $questionText = trim((string) $questionText);

        $questionType = $types[$index] ?? 'text';
        if (is_array($questionType)) {
            $questionType = reset($questionType) ?: 'text';
        }
        $questionType = trim((string) $questionType);

        $questionIdValue = $question_ids[$index] ?? '';
        if (is_array($questionIdValue)) {
            $questionIdValue = reset($questionIdValue) ?: '';
        }

        if ($questionText !== '') {
            $rawOptions = $options[$index] ?? [];

            if (is_array($rawOptions)) {
                $cleanOptions = [];
                foreach ($rawOptions as $opt) {
                    $opt = str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $opt);
                    $opt = trim($opt);
                    if ($opt !== '') {
                        $cleanOptions[] = $opt;
                    }
                }
                $optionsString = implode("\n", $cleanOptions);
            } else {
                $optionsString = str_replace(['<br>', '<br/>', '<br />'], "\n", trim((string) $rawOptions));
            }

            $validQuestions[] = [
                'id' => trim((string) $questionIdValue),
                'text' => $questionText,
                'type' => $questionType,
                'required' => isset($requireds[$index]) ? 1 : 0,
                'options' => $optionsString
            ];
        }
    }

    if (empty($validQuestions)) {
        $errors[] = 'Please add at least one question.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

                $currentSurveyType = $survey['survey_type'] ?? 'general';

    if ($currentSurveyType === 'tracer' && $is_active === 1) {
        $deactivateTracerStmt = $pdo->prepare("\n            UPDATE tracer_surveys\n            SET is_active = 0\n            WHERE survey_type = 'tracer'\n              AND id <> ?\n        ");
        $deactivateTracerStmt->execute([$survey_id]);
    }


            $updateSurveyStmt = $pdo->prepare("
                UPDATE tracer_surveys
                SET title = ?, description = ?, is_active = ?
                WHERE id = ?
            ");
            $updateSurveyStmt->execute([
                $title,
                $description !== '' ? $description : null,
                $is_active,
                $survey_id
            ]);

            $existingQuestionIdsStmt = $pdo->prepare("
                SELECT id
                FROM tracer_questions
                WHERE survey_id = ?
            ");
            $existingQuestionIdsStmt->execute([$survey_id]);
            $dbQuestionIds = $existingQuestionIdsStmt->fetchAll(PDO::FETCH_COLUMN);

            $submittedQuestionIds = [];
            $sortOrder = 1;

            foreach ($validQuestions as $q) {
                $questionId = $q['id'];

                if ($questionId !== '' && ctype_digit((string)$questionId)) {
                    $submittedQuestionIds[] = (int)$questionId;

                    $updateQuestionStmt = $pdo->prepare("
                        UPDATE tracer_questions
                        SET question_text = ?, question_type = ?, is_required = ?, sort_order = ?
                        WHERE id = ? AND survey_id = ?
                    ");
                    $updateQuestionStmt->execute([
                        $q['text'],
                        $q['type'],
                        $q['required'],
                        $sortOrder,
                        $questionId,
                        $survey_id
                    ]);

                    $deleteOptionsStmt = $pdo->prepare("
                        DELETE FROM tracer_question_options
                        WHERE question_id = ?
                    ");
                    $deleteOptionsStmt->execute([$questionId]);

                    if (in_array($q['type'], ['radio', 'select']) && $q['options'] !== '') {
                        $optionLines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $q['options'])));
                        foreach ($optionLines as $optionText) {
                            $optionStmt = $pdo->prepare("
                                INSERT INTO tracer_question_options (question_id, option_text)
                                VALUES (?, ?)
                            ");
                            $optionStmt->execute([$questionId, $optionText]);
                        }
                    }
                } else {
                    $insertQuestionStmt = $pdo->prepare("
                        INSERT INTO tracer_questions (survey_id, question_text, question_type, is_required, sort_order)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $insertQuestionStmt->execute([
                        $survey_id,
                        $q['text'],
                        $q['type'],
                        $q['required'],
                        $sortOrder
                    ]);

                    $newQuestionId = $pdo->lastInsertId();

                    if (in_array($q['type'], ['radio', 'select']) && $q['options'] !== '') {
                        $optionLines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $q['options'])));
                        foreach ($optionLines as $optionText) {
                            $optionStmt = $pdo->prepare("
                                INSERT INTO tracer_question_options (question_id, option_text)
                                VALUES (?, ?)
                            ");
                            $optionStmt->execute([$newQuestionId, $optionText]);
                        }
                    }
                }

                $sortOrder++;
            }

            foreach ($dbQuestionIds as $dbQuestionId) {
                if (!in_array((int)$dbQuestionId, $submittedQuestionIds)) {
                    $deleteQuestionStmt = $pdo->prepare("
                        DELETE FROM tracer_questions
                        WHERE id = ? AND survey_id = ?
                    ");
                    $deleteQuestionStmt->execute([$dbQuestionId, $survey_id]);
                }
            }

            $pdo->commit();

            header('Location: surveys.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while updating the survey.';
        }
    }
}

$pageTitle = 'Edit Survey';
$pageSubtitle = 'Update tracer survey details and questions.';
$activePage = 'surveys';

require_once '../includes/dashboard_header.php';
?>

<div class="panel-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="panel-title mb-0">Edit Tracer Survey</div>
        <a href="surveys.php" class="btn-light-outline">Back to Surveys</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger rounded-4">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="section-box mt-0">
            <div class="section-title">Survey Details</div>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Survey Title</label>
                    <input
                        type="text"
                        name="title"
                        class="form-control"
                        value="<?= htmlspecialchars($_POST['title'] ?? $survey['title'] ?? '') ?>"
                        required>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($_POST['description'] ?? $survey['description'] ?? '') ?></textarea>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <?php
                        $checkedActive = isset($_POST['title'])
                            ? isset($_POST['is_active'])
                            : ((int)($survey['is_active'] ?? 0) === 1);
                        ?>
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= $checkedActive ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">
                            Set survey as active
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
            <div class="panel-title mb-0">Survey Questions</div>
            <button type="button" class="btn btn-maroon" id="addQuestionBtn">Add Question</button>
        </div>

        <div id="questions-container"></div>

        <div class="d-grid mt-4">
            <button type="submit" class="btn btn-maroon btn-lg">Save Changes</button>
        </div>
    </form>
</div>
<script>
    const questionsContainer = document.getElementById('questions-container');
    const addQuestionBtn = document.getElementById('addQuestionBtn');

    let questionIndex = 0;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.innerText = text ?? '';
        return div.innerHTML;
    }

    function normalizeOptions(text) {
        if (Array.isArray(text)) {
            text = text.join('\n');
        }

        return String(text ?? '')
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/&lt;br\s*\/?&gt;/gi, '\n')
            .replace(/\r\n/g, '\n')
            .replace(/\r/g, '\n');
    }

    function updateQuestionNumbers() {
        const blocks = questionsContainer.querySelectorAll('.question-block');
        blocks.forEach((block, i) => {
            const number = block.querySelector('.question-number');
            if (number) {
                number.textContent = i + 1;
            }
        });
    }

    function toggleRemoveButtons() {
        const blocks = questionsContainer.querySelectorAll('.question-block');
        blocks.forEach(block => {
            const btn = block.querySelector('.remove-question-btn');
            if (btn) {
                btn.style.display = blocks.length > 1 ? 'inline-block' : 'none';
            }
        });
    }

    function createQuestionBlock(index, data = {}) {
        const questionId = data.id || '';
        const questionText = data.text || '';
        const questionType = data.type || 'text';
        const questionOptions = normalizeOptions(data.options || '');
        const isRequired = data.required === false ? '' : 'checked';

        const wrapper = document.createElement('div');
        wrapper.className = 'section-box question-block';
        wrapper.setAttribute('data-index', index);

        wrapper.innerHTML = `
            <input type="hidden" name="question_ids[${index}]" value="${escapeHtml(String(questionId))}">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="section-title mb-0">Question <span class="question-number"></span></div>
                <button type="button" class="btn btn-outline-danger btn-sm remove-question-btn">Remove</button>
            </div>

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Question Text</label>
                    <input type="text" name="questions[${index}]" class="form-control" value="${escapeHtml(questionText)}" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Question Type</label>
                    <select name="question_types[${index}]" class="form-select question-type-select">
                        <option value="text" ${questionType === 'text' ? 'selected' : ''}>Text</option>
                        <option value="textarea" ${questionType === 'textarea' ? 'selected' : ''}>Textarea</option>
                        <option value="radio" ${questionType === 'radio' ? 'selected' : ''}>Radio</option>
                        <option value="select" ${questionType === 'select' ? 'selected' : ''}>Select</option>
                    </select>
                </div>

              <div class="options-group" style="${(questionType === 'radio' || questionType === 'select') ? '' : 'display:none;'}">

    <label class="form-label">Options</label>

    <div class="options-container"></div>

    <button type="button" class="btn btn-sm btn-secondary add-option-btn">
        + Add Option
    </button>

</div>

                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="question_required[${index}]" id="required_${index}" ${isRequired}>
                        <label class="form-check-label" for="required_${index}">
                            Required question
                        </label>
                    </div>
                </div>
            </div>
        `;

        const typeSelect = wrapper.querySelector('.question-type-select');
        const optionsGroup = wrapper.querySelector('.options-group');
        const removeBtn = wrapper.querySelector('.remove-question-btn');
        // const optionsTextarea = wrapper.querySelector('.options-textarea');

        // // IMPORTANT: set textarea value directly
        // if (optionsTextarea) {
        //     optionsTextarea.value = questionOptions;
        // }

        const optionsContainer = wrapper.querySelector('.options-container');
        const addOptionBtn = wrapper.querySelector('.add-option-btn');

        function addOption(value = '') {
            const row = document.createElement('div');
            row.className = 'd-flex mb-2 option-row';

            row.innerHTML = `
        <input type="text"
            name="question_options[${index}][]"
            class="form-control me-2"
            value="${escapeHtml(value)}">

        <button type="button" class="btn btn-danger btn-sm remove-option">×</button>
    `;

            row.querySelector('.remove-option').addEventListener('click', () => row.remove());

            optionsContainer.appendChild(row);
        }

        let opts = normalizeOptions(data.options || '')
            .split('\n')
            .map(o => o.trim())
            .filter(o => o !== '');

        if (opts.length > 0) {
            opts.forEach(o => addOption(o));
        } else {
            addOption();
        }

        addOptionBtn.addEventListener('click', () => addOption());


        typeSelect.addEventListener('change', function() {
            if (this.value === 'radio' || this.value === 'select') {
                optionsGroup.style.display = '';
            } else {
                optionsGroup.style.display = 'none';
            }
        });

        removeBtn.addEventListener('click', function() {
            wrapper.remove();
            updateQuestionNumbers();
            toggleRemoveButtons();
        });

        questionsContainer.appendChild(wrapper);
        updateQuestionNumbers();
        toggleRemoveButtons();
    }

    addQuestionBtn.addEventListener('click', function() {
        createQuestionBlock(questionIndex++);
    });

    <?php
    $postedQuestions = $_POST['questions'] ?? [];
    $postedTypes = $_POST['question_types'] ?? [];
    $postedOptions = $_POST['question_options'] ?? [];
    $postedRequired = $_POST['question_required'] ?? [];
    $postedIds = $_POST['question_ids'] ?? [];

    $questionData = [];

    if (!empty($postedQuestions)) {
        foreach ($postedQuestions as $i => $q) {
            $postedQuestionText = is_array($q) ? '' : (string) $q;
            $postedOptionValue = $postedOptions[$i] ?? '';

            if (is_array($postedOptionValue)) {
                $postedOptionValue = implode("\n", array_filter(array_map('trim', $postedOptionValue)));
            } else {
                $postedOptionValue = str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $postedOptionValue);
            }

            if (
                trim($postedQuestionText) !== '' ||
                !empty($postedTypes[$i]) ||
                trim($postedOptionValue) !== '' ||
                !empty($postedIds[$i])
            ) {
                $questionData[] = [
                    'id' => $postedIds[$i] ?? '',
                    'text' => $postedQuestionText,
                    'type' => $postedTypes[$i] ?? 'text',
                    'options' => $postedOptionValue,
                    'required' => isset($postedRequired[$i])
                ];
            }
        }
    } else {
        foreach ($existingQuestions as $q) {
            $questionData[] = [
                'id' => $q['id'],
                'text' => $q['question_text'],
                'type' => $q['question_type'],
                'options' => $questionOptionsMap[$q['id']] ?? '',
                'required' => ((int)$q['is_required'] === 1)
            ];
        }
    }
    ?>

    const existingQuestions = <?= json_encode($questionData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    if (existingQuestions.length > 0) {
        existingQuestions.forEach(q => {
            createQuestionBlock(questionIndex++, q);
        });
    } else {
        createQuestionBlock(questionIndex++);
    }
</script>
<?php require_once '../includes/dashboard_footer.php'; ?>