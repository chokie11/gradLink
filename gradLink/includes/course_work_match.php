<?php
/**
 * Working alumni vs. course alignment (for the admin dashboard).
 *
 * "Working"  = latest tracer answer for employment status (fallback: profile status)
 *              that is Employed / Self-employed / Freelance / Underemployed.
 * "Matches"  = the job role / employer (tracer answers) or work experience (profile)
 *              contains keywords that belong to the alumni's course.
 * Working alumni with no job role at all are reported separately ("not provided").
 */

const ALUMNI_INDUSTRIES = [
    'Information Technology / Software',
    'Education / Training',
    'Accounting / Finance / Banking',
    'Business / Administration / Office',
    'Sales / Marketing / Retail',
    'BPO / Call Center',
    'Hospitality / Tourism / Food Service',
    'Manufacturing / Industrial / Technical',
    'Construction / Engineering',
    'Government / Public Service',
    'Healthcare',
    'Agriculture / Fishery',
    'Transportation / Logistics',
    'Other',
];

function courseWorkKeywords(): array
{
    $education = 'teacher|teaching|instructor|tutor|school|education|professor|faculty|lecturer|educator|principal|deped|adviser|learning facilitator|day care|daycare|kindergarten|preschool';
    return [
        'BSED'  => $education . '|trainer|guidance|academic',
        'BEED'  => $education . '|trainer|kindergarten|elementary',
        'BPED'  => $education . '|coach|fitness|sports|physical education|athletic|gym|pe teacher|recreation',
        'BTLED' => $education . '|technical|livelihood|tesda|home economics|tle|trainer|vocational',
        'BSA'   => 'account|accountant|accounting|bookkeep|audit|auditor|payroll|finance|financial|tax|taxation|cpa|treasury|billing|cashier|credit|collection|budget',
        'BSBA'  => 'marketing|sales|business|admin|administrative|management|manager|office|human resource|hr|entrepreneur|customer service|operations|clerk|secretary|bank|banking|retail|supervisor|merchandis|procurement|purchasing|logistics|virtual assistant|call center|bpo|store|owner',
        'BSIT'  => 'it|information technology|web|developer|programmer|programming|software|database|support|network|system|systems|technical|computer|helpdesk|help desk|qa|tester|data|cyber|frontend|backend|full stack|fullstack|designer|ui|ux|app|mobile|cloud|devops|technician',
        'BSHM'  => 'hospitality|hotel|restaurant|tourism|front desk|food|chef|cook|barista|housekeeping|waiter|waitress|server|resort|travel|cabin crew|catering|kitchen|bartender|bakery|baker|guest|concierge|banquet|flight attendant',
        'BSINDTECH' => 'technician|mechanic|electrician|electrical|welder|welding|machinist|automotive|manufacturing|production|maintenance|engineer|industrial|fabricat|plumb|operator|draft|cad|quality control|electronics|refrigeration|aircon|carpent|construction|assembly|technologist',
    ];
}

function courseMatchesWork(string $course, string $text): bool
{
    $course = strtoupper(trim($course));
    $text = strtolower(trim($text));
    if ($course === '' || $text === '') {
        return false;
    }
    $map = courseWorkKeywords();
    if (!isset($map[$course])) {
        return false;
    }
    static $patterns = [];
    if (!isset($patterns[$course])) {
        // Short words (<= 3 letters: it, hr, qa, app...) must match as whole words;
        // longer ones also match their endings (teach -> teacher, account -> accountant).
        $parts = [];
        foreach (explode('|', $map[$course]) as $word) {
            $parts[] = preg_quote($word, '/') . (strlen($word) <= 3 ? '\b' : '');
        }
        $patterns[$course] = '/\b(?:' . implode('|', $parts) . ')/iu';
    }
    return (bool) preg_match($patterns[$course], $text);
}

/**
 * 'match' | 'no_match' | 'unknown' for one working alumni.
 * The job position decides when it is known; otherwise company / industry / other text is used.
 */
function classifyWorkAgainstCourse(string $course, string $position, string $otherText): string
{
    $position = trim($position);
    $otherText = trim($otherText);

    if ($position !== '') {
        return courseMatchesWork($course, $position) ? 'match'
            : (courseMatchesWork($course, $position . ' ' . $otherText) ? 'match' : 'no_match');
    }
    if ($otherText === '') {
        return 'unknown';
    }
    return courseMatchesWork($course, $otherText) ? 'match' : 'no_match';
}

function isWorkingStatus(string $status): bool
{
    $s = strtolower(trim($status));
    if ($s === '' || str_contains($s, 'unemployed') || str_contains($s, 'student') || str_contains($s, 'further stud')) {
        return false;
    }
    return str_contains($s, 'employed') || str_contains($s, 'self') || str_contains($s, 'freelance') || str_contains($s, 'working');
}

/** Latest tracer answer per alumni for questions whose text contains one of $needles. */
function latestTracerAnswersByAlumni(PDO $pdo, array $needles): array
{
    $likes = [];
    $params = [];
    foreach ($needles as $n) {
        $likes[] = 'LOWER(q.question_text) LIKE ?';
        $params[] = '%' . strtolower($n) . '%';
    }

    $stmt = $pdo->prepare("
        SELECT r.alumni_id, TRIM(a.answer_text) AS answer_text
        FROM tracer_responses r
        INNER JOIN (
            SELECT tr.alumni_id, MAX(tr.id) AS latest_id
            FROM tracer_responses tr
            INNER JOIN tracer_surveys s ON s.id = tr.survey_id
            WHERE s.survey_type = 'tracer'
            GROUP BY tr.alumni_id
        ) latest ON latest.latest_id = r.id
        INNER JOIN tracer_answers a ON a.response_id = r.id
        INNER JOIN tracer_questions q ON q.id = a.question_id
        WHERE (" . implode(' OR ', $likes) . ")
          AND TRIM(a.answer_text) <> ''
    ");
    $stmt->execute($params);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['alumni_id'];
        $map[$id] = isset($map[$id]) ? $map[$id] . ' ' . $row['answer_text'] : $row['answer_text'];
    }
    return $map;
}

function getWorkCourseAlignment(PDO $pdo): array
{
    $result = [
        'total_alumni' => 0,
        'working' => 0,
        'match' => 0,
        'no_match' => 0,
        'unknown' => 0,
        'by_course' => [],
    ];

    $statusMap = latestTracerAnswersByAlumni($pdo, ['employment status', 'status of employment']);
    $roleMap = latestTracerAnswersByAlumni($pdo, ['job description', 'job role', 'job title', 'position']);
    $employerMap = latestTracerAnswersByAlumni($pdo, ['name of your employer']);

    $rows = $pdo->query("
        SELECT u.id, ap.course, ap.employment_status, ap.work_experience,
               ap.company_name, ap.job_position, ap.industry_type
        FROM users u
        LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
        WHERE u.role = 'alumni'
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $result['total_alumni']++;

        $profileStatus = trim((string) ($row['employment_status'] ?? ''));
        $status = $profileStatus !== '' ? $profileStatus : (string) ($statusMap[$id] ?? '');
        if (!isWorkingStatus($status)) {
            continue;
        }

        $course = trim((string) ($row['course'] ?? '')) ?: 'Not set';
        // Profile "Current Work Details" first; tracer answers / work experience as backup.
        $position = trim((string) ($row['job_position'] ?? '')) ?: trim((string) ($roleMap[$id] ?? ''));
        $other = trim(
            (string) ($row['company_name'] ?? '') . ' ' . (string) ($row['industry_type'] ?? '') . ' ' .
            ($employerMap[$id] ?? '') . ' ' . (string) ($row['work_experience'] ?? '')
        );
        $bucket = classifyWorkAgainstCourse($course, $position, $other);

        $result['working']++;
        $result[$bucket]++;

        if (!isset($result['by_course'][$course])) {
            $result['by_course'][$course] = ['working' => 0, 'match' => 0, 'no_match' => 0, 'unknown' => 0];
        }
        $result['by_course'][$course]['working']++;
        $result['by_course'][$course][$bucket]++;
    }

    ksort($result['by_course']);
    return $result;
}
