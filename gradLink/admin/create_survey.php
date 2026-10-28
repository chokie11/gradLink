<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $survey_type = $_POST['survey_type'] ?? 'general';

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

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

        if ($questionText !== '') {
            $validQuestions[] = [
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


    if ($survey_type === 'tracer' && $is_active === 1) {
        $deactivateTracerStmt = $pdo->prepare("\n            UPDATE tracer_surveys\n            SET is_active = 0\n            WHERE survey_type = 'tracer'\n        ");
        $deactivateTracerStmt->execute();
    }

    

            $surveyStmt = $pdo->prepare("
                INSERT INTO tracer_surveys (title, description, survey_type, is_active, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $surveyStmt->execute([
                $title,
                $description !== '' ? $description : null,
                $survey_type,
                $is_active,
                $_SESSION['user_id']
            ]);


            $surveyId = $pdo->lastInsertId();

            foreach ($validQuestions as $i => $q) {
                $questionStmt = $pdo->prepare("
                    INSERT INTO tracer_questions (survey_id, question_text, question_type, is_required, sort_order)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $questionStmt->execute([
                    $surveyId,
                    $q['text'],
                    $q['type'],
                    $q['required'],
                    $i + 1
                ]);

                $questionId = $pdo->lastInsertId();
                if (in_array($q['type'], ['radio', 'select'], true) && trim((string) $q['options']) !== '') {
                    $optionLines = preg_split('/\r\n|\r|\n/', (string) $q['options']);

                    foreach ($optionLines as $opt) {
                        $opt = trim($opt);
                        if ($opt === '') {
                            continue;
                        }

                        $optStmt = $pdo->prepare("
                            INSERT INTO tracer_question_options (question_id, option_text)
                            VALUES (?, ?)
                        ");
                        $optStmt->execute([$questionId, $opt]);
                    }
                }
            }

            $pdo->commit();
            header('Location: surveys.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while creating the survey.';
        }
    }
}

$pageTitle = 'Create Survey';
$pageSubtitle = 'Create a tracer survey and add questions for alumni.';
$activePage = 'surveys';

require_once '../includes/dashboard_header.php';
?>

<div class="panel-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="panel-title mb-0">Create Tracer Survey</div>
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

            <div class="mb-3">
                <label>Survey Type</label>
                <select name="survey_type" id="survey_type" class="form-control" required>
                    <option value="general">General Survey</option>
                    <option value="tracer">Tracer Study Survey</option>
                </select>
            </div>

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Survey Title</label>
                    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= isset($_POST['is_active']) || !isset($_POST['title']) ? 'checked' : '' ?>>
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
            <button type="submit" class="btn btn-maroon btn-lg">Create Survey</button>
        </div>
    </form>
</div>

<script>

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

    const tracerTemplate = [{
            text: "What is your employment status at present?",
            type: "radio",
            options: "Employed\nSelf-employed\nUnemployed\nFreelance Worker"
        },
        {
            text: "Please indicate the name of your employer",
            type: "text"
        },
        {
            text: "What is your job description/role?",
            type: "textarea"
        },
        {
            text: "What sector do you work for?",
            type: "radio",
            options: "Government\nPrivate\nSelf-employed\nN/A"
        },
        {
            text: "Where is your work location?",
            type: "select",
            options: "Region I\nRegion II\nRegion III\nNCR\nOverseas"
        },
        {
            text: "Are you currently pursuing further studies?",
            type: "radio",
            options: "Yes\nNo"
        },
        {
            text: "What skills did you learn from the university?",
            type: "textarea"
        },
        {
            text: "What challenges did you face in finding a job?",
            type: "textarea"
        },
        {
            text: "How would you rate your overall learning experience?",
            type: "radio",
            options: "Excellent\nOutstanding\nVery Satisfactory\nSatisfactory\nFair\nNeeds Improvement"
        }
    ];
</script>

<script>
    const questionsContainer = document.getElementById('questions-container');
    const addQuestionBtn = document.getElementById('addQuestionBtn');

    let questionIndex = 0;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.innerText = text ?? '';
        return div.innerHTML;
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

function parseOptions(text) {
    return (text || '')
        .replace(/<br\s*\/?>/gi, '\n')
        .split('\n')
        .map(o => o.trim())
        .filter(o => o !== '');
}

    function createQuestionBlock(index, data = {}) {
        const questionText = data.text || '';
        const questionType = data.type || 'text';
const questionOptions = normalizeOptions(data.options || '');
        const isRequired = data.required === false ? '' : 'checked';

        const wrapper = document.createElement('div');
        wrapper.className = 'section-box question-block';
        wrapper.setAttribute('data-index', index);

        wrapper.innerHTML = `
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

           <div class="col-md-5 options-group" style="${(questionType === 'radio' || questionType === 'select') ? '' : 'display:none;'}">

    <label class="form-label">Options</label>

    <div class="options-container"></div>

    <button type="button" class="btn btn-sm btn-secondary mt-2 add-option-btn">
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
        const optionsContainer = wrapper.querySelector('.options-container');
const addOptionBtn = wrapper.querySelector('.add-option-btn');

function addOption(value = '') {
    const row = document.createElement('div');
    row.className = 'd-flex mb-2 option-row';

    row.innerHTML = `
        <input type="text" name="question_options[${index}][]" 
            class="form-control me-2" value="${escapeHtml(value)}">

        <button type="button" class="btn btn-danger btn-sm remove-option">×</button>
    `;

    row.querySelector('.remove-option').addEventListener('click', function () {
        row.remove();
    });

    optionsContainer.appendChild(row);
}

if (questionOptions && (questionType === 'radio' || questionType === 'select')) {

    questionOptions
        .replace(/<br\s*\/?>/gi, '\n')
        .split('\n')
        .forEach(opt => {
            if (opt.trim() !== '') addOption(opt.trim());
        });

} else if (questionType === 'radio' || questionType === 'select') {
    addOption();
}

addOptionBtn.addEventListener('click', function () {
    addOption('');
});


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
                trim($postedOptionValue) !== ''
            ) {
                $questionData[] = [
                    'text' => $postedQuestionText,
                    'type' => $postedTypes[$i] ?? 'text',
                    'options' => $postedOptionValue,
                    'required' => isset($postedRequired[$i])
                ];
            }
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

    document.getElementById('survey_type').addEventListener('change', function() {

        const type = this.value;

        const container = document.getElementById('questions-container');

        // RESET always
        container.innerHTML = '';
        questionIndex = 0;

        if (type === 'tracer') {

            // AUTO LOAD DEPED TEMPLATE
            tracerTemplate.forEach(q => {

    createQuestionBlock(questionIndex++, {
        text: q.text,
        type: q.type,
        options: (q.options || '')
            .replaceAll('<br>', '\n')
            .replaceAll('<br/>', '\n')
            .replaceAll('<br />', '\n'),
        required: true
    });

});

            updateQuestionNumbers();
            toggleRemoveButtons();

        } else {
            // GENERAL SURVEY → start empty
            container.innerHTML = '';
            questionIndex = 0;

            // optional: auto add 1 empty question
            createQuestionBlock(questionIndex++);
        }
    });
</script>

<?php require_once '../includes/dashboard_footer.php'; ?>