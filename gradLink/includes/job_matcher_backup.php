<?php

require_once __DIR__ . '/../config/ai.php';

function normalizeMatcherText(?string $text): string
{
    $text = (string) $text;
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function splitMatcherKeywords(?string $text): array
{
    $text = strtolower((string) $text);
    $parts = preg_split('/[,;\n\r|]+/', $text);
    $clean = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '' && mb_strlen($part) >= 2) {
            $clean[] = $part;
        }
    }

    return array_values(array_unique($clean));
}

function getMatchLevel(int $score): string
{
    if ($score >= 85) {
        return 'Excellent';
    }
    if ($score >= 70) {
        return 'High';
    }
    if ($score >= 55) {
        return 'Moderate';
    }
    return 'Low';
}

function localJobMatchScore(array $profile, array $job): array
{
    $score = 25;

    $jobText = strtolower(normalizeMatcherText(
        ($job['title'] ?? '') . ' ' .
        ($job['description'] ?? '') . ' ' .
        ($job['requirements'] ?? '') . ' ' .
        ($job['employment_type'] ?? '') . ' ' .
        ($job['location'] ?? '') . ' ' .
        ($job['industry_type'] ?? '')
    ));

    $course = strtolower(trim((string) ($profile['course'] ?? '')));
    $major = strtolower(trim((string) ($profile['major'] ?? '')));

    if ($course !== '' && strpos($jobText, $course) !== false) {
        $score += 12;
    }

    if ($major !== '' && strpos($jobText, $major) !== false) {
        $score += 10;
    }

    $matchedSkills = [];
    foreach (splitMatcherKeywords($profile['skills'] ?? '') as $skill) {
        if (strpos($jobText, $skill) !== false) {
            $matchedSkills[] = ucwords($skill);
            $score += 8;
        }
    }

    foreach (splitMatcherKeywords($profile['preferred_job_titles'] ?? '') as $title) {
        if (strpos($jobText, $title) !== false || strpos(strtolower((string) ($job['title'] ?? '')), $title) !== false) {
            $score += 12;
        }
    }

    foreach (splitMatcherKeywords($profile['career_interests'] ?? '') as $interest) {
        if (strpos($jobText, $interest) !== false) {
            $score += 5;
        }
    }

    $preferredLocation = strtolower(trim((string) ($profile['preferred_location'] ?? '')));
    if ($preferredLocation !== '' && strpos(strtolower((string) ($job['location'] ?? '')), $preferredLocation) !== false) {
        $score += 8;
    }

    $preferredSetup = strtolower(trim((string) ($profile['preferred_work_setup'] ?? '')));
    if ($preferredSetup !== '' && strpos($jobText, $preferredSetup) !== false) {
        $score += 5;
    }

    if ($course === 'bsit' && preg_match('/\b(it|information technology|web|developer|programmer|technical|software|database|support)\b/', $jobText)) {
        $score += 16;
    }

    if ($course === 'bsba' && preg_match('/\b(marketing|sales|business|admin|management|office)\b/', $jobText)) {
        $score += 16;
    }

    if ($course === 'bsa' && preg_match('/\b(accounting|finance|bookkeeping|audit|payroll)\b/', $jobText)) {
        $score += 16;
    }

    if ($course === 'bshm' && preg_match('/\b(hospitality|hotel|restaurant|tourism|front desk|food)\b/', $jobText)) {
        $score += 16;
    }

    if (in_array($course, ['beed', 'bsed', 'bped', 'btled'], true) && preg_match('/\b(teacher|education|school|instructor|trainer|tutor)\b/', $jobText)) {
        $score += 16;
    }

    $score = max(0, min(100, $score));

    $reason = 'Matched using your profile details.';
    if (!empty($matchedSkills)) {
        $reason = 'Matched skills: ' . implode(', ', array_slice($matchedSkills, 0, 5)) . '.';
    } elseif ($course !== '') {
        $reason = 'Matched mainly based on your course and career information.';
    }

    return [
        'match_score' => $score,
        'match_level' => getMatchLevel($score),
        'ai_reason' => $reason,
        'missing_skills' => '',
        'suggested_action' => $score >= 70
            ? 'You are a strong fit. Review the requirements and apply if interested.'
            : 'Improve your profile skills or check the job requirements before applying.',
        'source' => 'Local Matcher'
    ];
}

function parseGeminiJson(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/^```\s*/', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);

    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : null;
}

function matchJobWithGemini(array $profile, array $job): array
{
    $local = localJobMatchScore($profile, $job);

    if (!defined('USE_GEMINI') || !USE_GEMINI || !defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
        return $local;
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent';

    $profileData = [
        'course' => $profile['course'] ?? '',
        'major' => $profile['major'] ?? '',
        'graduation_year' => $profile['graduation_year'] ?? '',
        'employment_status' => $profile['employment_status'] ?? '',
        'bio' => $profile['bio'] ?? '',
        'skills' => $profile['skills'] ?? '',
        'preferred_job_titles' => $profile['preferred_job_titles'] ?? '',
        'career_interests' => $profile['career_interests'] ?? '',
        'certifications' => $profile['certifications'] ?? '',
        'work_experience' => $profile['work_experience'] ?? '',
        'preferred_location' => $profile['preferred_location'] ?? '',
        'preferred_work_setup' => $profile['preferred_work_setup'] ?? '',
        'expected_salary' => $profile['expected_salary'] ?? ''
    ];

    $jobData = [
        'title' => $job['title'] ?? '',
        'company_name' => $job['company_name'] ?? '',
        'industry_type' => $job['industry_type'] ?? '',
        'description' => $job['description'] ?? '',
        'requirements' => $job['requirements'] ?? '',
        'location' => $job['location'] ?? '',
        'employment_type' => $job['employment_type'] ?? '',
        'salary_range' => $job['salary_range'] ?? ''
    ];

    $prompt = "Analyze how well this alumni profile matches this job. Return ONLY valid JSON in this exact format:\n\n" .
        "{\n" .
        "  \"match_score\": 0,\n" .
        "  \"match_level\": \"Low|Moderate|High|Excellent\",\n" .
        "  \"reason\": \"1 short reason for the match\",\n" .
        "  \"missing_skills\": [\"skill1\", \"skill2\"],\n" .
        "  \"suggested_action\": \"short advice\"\n" .
        "}\n\n" .
        "Scoring guide: 85-100 Excellent, 70-84 High, 55-69 Moderate, below 55 Low.\n" .
        "Consider skills, course, major, experience, certifications, preferred roles, location, work setup, and job requirements.\n" .
        "Return JSON only. No markdown.\n\n" .
        "Alumni profile:\n" . json_encode($profileData, JSON_PRETTY_PRINT) . "\n\n" .
        "Job post:\n" . json_encode($jobData, JSON_PRETTY_PRINT);

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.2,
            'maxOutputTokens' => 600
        ]
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-goog-api-key: ' . GEMINI_API_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 25
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        return $local;
    }

    $decoded = json_decode($response, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $json = parseGeminiJson((string) $text);

    if (!is_array($json)) {
        return $local;
    }

    $score = (int) ($json['match_score'] ?? $local['match_score']);
    $score = max(0, min(100, $score));

    $missingSkills = $json['missing_skills'] ?? [];
    if (is_array($missingSkills)) {
        $missingSkills = implode(', ', array_map('trim', $missingSkills));
    }

    return [
        'match_score' => $score,
        'match_level' => trim((string) ($json['match_level'] ?? getMatchLevel($score))),
        'ai_reason' => trim((string) ($json['reason'] ?? $local['ai_reason'])),
        'missing_skills' => trim((string) $missingSkills),
        'suggested_action' => trim((string) ($json['suggested_action'] ?? $local['suggested_action'])),
        'source' => 'Gemini AI'
    ];
}

function getCachedRecommendation(PDO $pdo, int $alumniId, int $jobId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM job_recommendations WHERE alumni_id = ? AND job_post_id = ? LIMIT 1");
    $stmt->execute([$alumniId, $jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function saveRecommendation(PDO $pdo, int $alumniId, int $jobId, array $match): void
{
    $stmt = $pdo->prepare("\n        INSERT INTO job_recommendations\n            (alumni_id, job_post_id, match_score, match_level, ai_reason, missing_skills, suggested_action)\n        VALUES\n            (?, ?, ?, ?, ?, ?, ?)\n        ON DUPLICATE KEY UPDATE\n            match_score = VALUES(match_score),\n            match_level = VALUES(match_level),\n            ai_reason = VALUES(ai_reason),\n            missing_skills = VALUES(missing_skills),\n            suggested_action = VALUES(suggested_action),\n            updated_at = CURRENT_TIMESTAMP\n    ");

    $stmt->execute([
        $alumniId,
        $jobId,
        (int) ($match['match_score'] ?? 0),
        $match['match_level'] ?? '',
        $match['ai_reason'] ?? '',
        $match['missing_skills'] ?? '',
        $match['suggested_action'] ?? ''
    ]);
}

function getJobRecommendation(PDO $pdo, int $alumniId, array $profile, array $job, bool $refresh = false): array
{
    if (!$refresh) {
        $cached = getCachedRecommendation($pdo, $alumniId, (int) $job['id']);
        if ($cached) {
            return [
                'match_score' => (int) $cached['match_score'],
                'match_level' => $cached['match_level'] ?? getMatchLevel((int) $cached['match_score']),
                'ai_reason' => $cached['ai_reason'] ?? '',
                'missing_skills' => $cached['missing_skills'] ?? '',
                'suggested_action' => $cached['suggested_action'] ?? '',
                'source' => 'Saved Recommendation'
            ];
        }
    }

    $match = matchJobWithGemini($profile, $job);
    saveRecommendation($pdo, $alumniId, (int) $job['id'], $match);
    return $match;
}
