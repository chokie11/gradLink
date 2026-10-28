<?php

require_once __DIR__ . '/../config/ai.php';

function cleanProfileAiText(?string $text): string
{
    $text = (string) $text;
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function parseProfileAiJson(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/^```\s*/', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);

    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : null;
}

function localProfileSummary(array $profile): array
{
    $name = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
    $course = cleanProfileAiText($profile['course'] ?? '');
    $major = cleanProfileAiText($profile['major'] ?? '');
    $skills = cleanProfileAiText($profile['skills'] ?? '');
    $preferredTitles = cleanProfileAiText($profile['preferred_job_titles'] ?? '');
    $interests = cleanProfileAiText($profile['career_interests'] ?? '');
    $experience = cleanProfileAiText($profile['work_experience'] ?? '');

    $parts = [];
    if ($name !== '') {
        $parts[] = $name;
    } else {
        $parts[] = 'This alumni';
    }

    if ($course !== '') {
        $parts[] = 'completed ' . $course . ($major !== '' ? ' with ' . $major : '');
    }

    if ($skills !== '') {
        $parts[] = 'has skills in ' . $skills;
    }

    if ($preferredTitles !== '') {
        $parts[] = 'is targeting roles such as ' . $preferredTitles;
    }

    if ($experience !== '') {
        $parts[] = 'has experience including ' . $experience;
    }

    $summary = implode(', ', $parts) . '.';

    $tagsSource = $skills . ',' . $preferredTitles . ',' . $interests . ',' . $course . ',' . $major;
    $tags = preg_split('/[,;\n\r|]+/', strtolower($tagsSource));
    $cleanTags = [];
    foreach ($tags as $tag) {
        $tag = trim($tag);
        if ($tag !== '' && strlen($tag) >= 2) {
            $cleanTags[] = ucwords($tag);
        }
    }
    $cleanTags = array_values(array_unique(array_slice($cleanTags, 0, 12)));

    return [
        'summary' => $summary,
        'tags' => implode(', ', $cleanTags),
        'source' => 'Local Summary'
    ];
}

function generateAiProfileSummary(array $profile): array
{
    $local = localProfileSummary($profile);

    if (!defined('USE_GEMINI') || !USE_GEMINI || !defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
        return [
            'success' => true,
            'summary' => $local['summary'],
            'tags' => $local['tags'],
            'source' => $local['source'],
            'message' => 'Generated a local summary. Add a valid Gemini API key to enable AI summaries.'
        ];
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent';

    $profileData = [
        'name' => trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')),
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
        'expected_salary' => $profile['expected_salary'] ?? '',
        'available_start_date' => $profile['available_start_date'] ?? ''
    ];

    $prompt = "Create an alumni career profile summary for job matching. Return ONLY valid JSON in this exact format:\n\n" .
        "{\n" .
        "  \"summary\": \"2 to 3 sentence professional career summary\",\n" .
        "  \"career_tags\": [\"tag1\", \"tag2\", \"tag3\"]\n" .
        "}\n\n" .
        "Rules:\n" .
        "- Be accurate. Do not invent degrees, skills, licenses, or experience.\n" .
        "- Make the summary useful for matching jobs.\n" .
        "- Use simple professional English.\n" .
        "- career_tags should contain 6 to 12 short tags based on course, skills, preferred jobs, interests, certifications, and experience.\n" .
        "- Return JSON only. No markdown.\n\n" .
        "Alumni profile:\n" . json_encode($profileData, JSON_PRETTY_PRINT);

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
            'maxOutputTokens' => 700
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
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        return [
            'success' => true,
            'summary' => $local['summary'],
            'tags' => $local['tags'],
            'source' => $local['source'],
            'message' => 'Gemini could not generate the summary, so a local summary was saved instead. ' . ($curlError ?: 'HTTP ' . $httpCode)
        ];
    }

    $decoded = json_decode($response, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $json = parseProfileAiJson((string) $text);

    if (!is_array($json)) {
        return [
            'success' => true,
            'summary' => $local['summary'],
            'tags' => $local['tags'],
            'source' => $local['source'],
            'message' => 'Gemini returned an invalid format, so a local summary was saved instead.'
        ];
    }

    $summary = cleanProfileAiText($json['summary'] ?? '');
    $tags = $json['career_tags'] ?? [];
    if (is_array($tags)) {
        $tags = implode(', ', array_map('trim', $tags));
    }
    $tags = cleanProfileAiText((string) $tags);

    if ($summary === '') {
        $summary = $local['summary'];
    }
    if ($tags === '') {
        $tags = $local['tags'];
    }

    return [
        'success' => true,
        'summary' => $summary,
        'tags' => $tags,
        'source' => 'Gemini AI',
        'message' => 'AI career summary generated successfully.'
    ];
}
