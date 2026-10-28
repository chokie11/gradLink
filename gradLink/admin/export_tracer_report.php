<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

function cleanCsvValue($value)
{
    $value = (string) ($value ?? '');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/', ' ', $value);
    return trim($value);
}

function answerValueForExport($answers, $keywords)
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

function classifyEmploymentForExport($answer)
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

function employmentCategoryLabelForExport($category)
{
    $labels = [
        'employed' => 'Employed',
        'self' => 'Self-employed / Freelance',
        'underemployed' => 'Underemployed',
        'unemployed' => 'Unemployed',
        'other' => 'Other',
        'unknown' => 'No Status'
    ];

    return isset($labels[$category]) ? $labels[$category] : 'No Status';
}

function makeSafeQuestionHeader($questionText, $count)
{
    $questionText = trim(preg_replace('/\s+/', ' ', (string) $questionText));
    $header = 'Q' . $count . ': ' . $questionText;

    if (strlen($header) > 120) {
        $header = substr($header, 0, 117) . '...';
    }

    return $header;
}

$selectedCourse = trim($_GET['course'] ?? '');
$selectedYear = trim($_GET['year'] ?? '');
$selectedStatus = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');

$activeSurveyStmt = $pdo->prepare("\n    SELECT *\n    FROM tracer_surveys\n    WHERE survey_type = 'tracer'\n      AND is_active = 1\n    ORDER BY id DESC\n    LIMIT 1\n");
$activeSurveyStmt->execute();
$activeTracerSurvey = $activeSurveyStmt->fetch(PDO::FETCH_ASSOC);

if (!$activeTracerSurvey) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "No active tracer survey found. Please activate a tracer survey first.";
    exit;
}

$surveyId = (int) $activeTracerSurvey['id'];

$questionStmt = $pdo->prepare("\n    SELECT id, question_text, sort_order\n    FROM tracer_questions\n    WHERE survey_id = ?\n    ORDER BY sort_order ASC, id ASC\n");
$questionStmt->execute([$surveyId]);
$questions = $questionStmt->fetchAll(PDO::FETCH_ASSOC);

$latestStmt = $pdo->prepare("\n    SELECT\n        tr.id AS response_id,\n        tr.alumni_id,\n        tr.submitted_at,\n        tr.survey_version,\n        u.first_name,\n        u.last_name,\n        u.email,\n        ap.student_id,\n        ap.course,\n        ap.major,\n        ap.graduation_year,\n        ap.phone\n    FROM tracer_responses tr\n    INNER JOIN (\n        SELECT alumni_id, MAX(id) AS latest_response_id\n        FROM tracer_responses\n        WHERE survey_id = ?\n        GROUP BY alumni_id\n    ) latest ON latest.latest_response_id = tr.id\n    INNER JOIN users u ON tr.alumni_id = u.id\n    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id\n    WHERE tr.survey_id = ?\n    ORDER BY tr.submitted_at DESC, tr.id DESC\n");
$latestStmt->execute([$surveyId, $surveyId]);
$latestRows = $latestStmt->fetchAll(PDO::FETCH_ASSOC);

$answerMap = [];
$answerByQuestionMap = [];

if (!empty($latestRows)) {
    $responseIds = array_column($latestRows, 'response_id');
    $placeholders = implode(',', array_fill(0, count($responseIds), '?'));

    $answersStmt = $pdo->prepare("\n        SELECT\n            ta.response_id,\n            ta.question_id,\n            tq.question_text,\n            tq.question_type,\n            tq.sort_order,\n            ta.answer_text\n        FROM tracer_answers ta\n        INNER JOIN tracer_questions tq ON ta.question_id = tq.id\n        WHERE ta.response_id IN ($placeholders)\n        ORDER BY ta.response_id ASC, tq.sort_order ASC, tq.id ASC\n    ");
    $answersStmt->execute($responseIds);
    $allAnswers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allAnswers as $answer) {
        $responseId = $answer['response_id'];
        $questionId = $answer['question_id'];
        $answerText = cleanCsvValue($answer['answer_text'] ?? '');

        if (!isset($answerMap[$responseId])) {
            $answerMap[$responseId] = [];
        }
        $answerMap[$responseId][] = $answer;

        if (!isset($answerByQuestionMap[$responseId])) {
            $answerByQuestionMap[$responseId] = [];
        }
        if (!isset($answerByQuestionMap[$responseId][$questionId])) {
            $answerByQuestionMap[$responseId][$questionId] = [];
        }
        $answerByQuestionMap[$responseId][$questionId][] = $answerText;
    }
}

$records = [];

foreach ($latestRows as $row) {
    $responseId = $row['response_id'];
    $answers = isset($answerMap[$responseId]) ? $answerMap[$responseId] : [];

    $employmentStatus = answerValueForExport($answers, ['employment status', 'status of employment']);
    $employmentCategory = classifyEmploymentForExport($employmentStatus);
    $furtherStudies = answerValueForExport($answers, ['further studies', 'pursuing further']);

    $record = [
        'response_id' => $responseId,
        'alumni_id' => $row['alumni_id'],
        'first_name' => $row['first_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
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
        'answer_by_question' => isset($answerByQuestionMap[$responseId]) ? $answerByQuestionMap[$responseId] : [],
        'employment_status' => $employmentStatus,
        'employment_category' => $employmentCategory,
        'employment_category_label' => employmentCategoryLabelForExport($employmentCategory),
        'employer_name' => answerValueForExport($answers, ['name of your employer', 'employer name', 'employer']),
        'job_role' => answerValueForExport($answers, ['job description', 'job role', 'job title', 'role']),
        'sector' => answerValueForExport($answers, ['sector']),
        'work_location' => answerValueForExport($answers, ['work location', 'where is your work', 'location']),
        'further_studies' => $furtherStudies,
        'skills' => answerValueForExport($answers, ['skills']),
        'challenges' => answerValueForExport($answers, ['challenges'])
    ];

    if ($selectedCourse !== '' && $record['course'] !== $selectedCourse) {
        continue;
    }

    if ($selectedYear !== '' && (string) $record['graduation_year'] !== (string) $selectedYear) {
        continue;
    }

    if ($selectedStatus !== '' && $record['employment_category'] !== $selectedStatus) {
        continue;
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
            continue;
        }
    }

    $records[] = $record;
}

$filenameDate = date('Y-m-d');
$filename = 'tracer_current_report_' . $filenameDate . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM for better Excel compatibility.
fwrite($output, "\xEF\xBB\xBF");

$baseHeaders = [
    'Response ID',
    'Alumni ID',
    'Student ID',
    'First Name',
    'Last Name',
    'Full Name',
    'Email',
    'Phone',
    'Course',
    'Major',
    'Graduation Year',
    'Employment Status',
    'Employment Category',
    'Employer / Company',
    'Job Role / Description',
    'Sector',
    'Work Location',
    'Further Studies',
    'Skills',
    'Job Search Challenges',
    'Submitted Date',
    'Survey Title',
    'Survey Version'
];

$questionHeaders = [];
$questionCounter = 1;
foreach ($questions as $question) {
    $questionHeaders[] = makeSafeQuestionHeader($question['question_text'], $questionCounter);
    $questionCounter++;
}

fputcsv($output, array_merge($baseHeaders, $questionHeaders));

foreach ($records as $record) {
    $row = [
        cleanCsvValue($record['response_id']),
        cleanCsvValue($record['alumni_id']),
        cleanCsvValue($record['student_id']),
        cleanCsvValue($record['first_name']),
        cleanCsvValue($record['last_name']),
        cleanCsvValue($record['name']),
        cleanCsvValue($record['email']),
        cleanCsvValue($record['phone']),
        cleanCsvValue($record['course']),
        cleanCsvValue($record['major']),
        cleanCsvValue($record['graduation_year']),
        cleanCsvValue($record['employment_status']),
        cleanCsvValue($record['employment_category_label']),
        cleanCsvValue($record['employer_name']),
        cleanCsvValue($record['job_role']),
        cleanCsvValue($record['sector']),
        cleanCsvValue($record['work_location']),
        cleanCsvValue($record['further_studies']),
        cleanCsvValue($record['skills']),
        cleanCsvValue($record['challenges']),
        cleanCsvValue($record['submitted_at']),
        cleanCsvValue($activeTracerSurvey['title'] ?? ''),
        cleanCsvValue($record['survey_version'])
    ];

    foreach ($questions as $question) {
        $qid = $question['id'];
        $answersForQuestion = isset($record['answer_by_question'][$qid]) ? $record['answer_by_question'][$qid] : [];
        $row[] = cleanCsvValue(implode(' | ', $answersForQuestion));
    }

    fputcsv($output, $row);
}

fclose($output);
exit;
