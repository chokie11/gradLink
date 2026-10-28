<?php

require_once __DIR__ . '/../config/ai.php';

function normalizeResumeText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function localExtractSkills(string $text): string
{
    $text = strtolower($text);

    $keywords = [
        'php', 'mysql', 'javascript', 'html', 'css', 'bootstrap',
        'python', 'java', 'c++', 'laravel', 'react', 'node', 'sql',
        'git', 'github', 'excel', 'word', 'powerpoint',
        'communication', 'leadership', 'teamwork', 'problem solving',
        'customer service', 'marketing', 'sales', 'bookkeeping',
        'troubleshooting', 'networking', 'web development'
    ];

    $found = [];

    foreach ($keywords as $skill) {
        if (strpos($text, strtolower($skill)) !== false) {
            $found[] = ucwords($skill);
        }
    }

    return implode(', ', array_unique($found));
}

function localExtractSummary(string $text): string
{
    if ($text === '') {
        return '';
    }

    $sentences = preg_split('/(?<=[.!?])\s+/', $text);
    return trim(implode(' ', array_slice($sentences, 0, 2)));
}

function localExtractRecommendedRoles(string $text): string
{
    $text = strtolower($text);
    $roles = [];

    if (preg_match('/php|html|css|javascript|web/', $text)) {
        $roles[] = 'Web Developer';
    }
    if (preg_match('/mysql|sql|database/', $text)) {
        $roles[] = 'Database Assistant';
    }
    if (preg_match('/marketing|sales/', $text)) {
        $roles[] = 'Marketing Assistant';
    }
    if (preg_match('/accounting|finance|bookkeeping/', $text)) {
        $roles[] = 'Accounting Staff';
    }
    if (preg_match('/customer service|communication/', $text)) {
        $roles[] = 'Customer Service Representative';
    }

    return implode(', ', array_unique($roles));
}

function localExtractStrengths(string $text): string
{
    $text = strtolower($text);

    $map = [
        'communication' => 'Communication',
        'leadership' => 'Leadership',
        'teamwork' => 'Teamwork',
        'problem solving' => 'Problem Solving',
        'customer service' => 'Customer Service',
        'troubleshooting' => 'Troubleshooting'
    ];

    $found = [];

    foreach ($map as $needle => $label) {
        if (strpos($text, $needle) !== false) {
            $found[] = $label;
        }
    }

    return implode(', ', array_unique($found));
}

function localAnalyzeResume(string $text): array
{
    $text = normalizeResumeText($text);

    return [
        'skills' => localExtractSkills($text),
        'summary' => localExtractSummary($text),
        'recommended_roles' => localExtractRecommendedRoles($text),
        'strengths' => localExtractStrengths($text),
        'source' => 'Local Analyzer'
    ];
}

function analyzeResumeWithGemini(string $resumeText): array
{
    $resumeText = normalizeResumeText($resumeText);

    if (!USE_GEMINI || GEMINI_API_KEY === '' || $resumeText === '') {
        return localAnalyzeResume($resumeText);
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent';

    $prompt = <<<PROMPT
Analyze this resume text and return ONLY valid JSON in this exact format:

{
  "skills": ["skill1", "skill2", "skill3"],
  "summary": "2-3 sentence professional summary",
  "recommended_roles": ["role1", "role2", "role3"],
  "strengths": ["strength1", "strength2", "strength3"]
}

Rules:
- Return only JSON
- No markdown
- Keep skills concise
- Keep summary professional
- If missing information, return empty arrays or empty string

Resume text:
PROMPT;

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt . "\n\n" . $resumeText]
                ]
            ]
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
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        return localAnalyzeResume($resumeText);
    }

    $decoded = json_decode($response, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if ($text === '') {
        return localAnalyzeResume($resumeText);
    }

    $text = trim($text);
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/^```\s*/', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);

    $json = json_decode($text, true);

    if (!is_array($json)) {
        return localAnalyzeResume($resumeText);
    }

    return [
        'skills' => !empty($json['skills']) && is_array($json['skills'])
            ? implode(', ', array_map('trim', $json['skills']))
            : '',
        'summary' => trim((string)($json['summary'] ?? '')),
        'recommended_roles' => !empty($json['recommended_roles']) && is_array($json['recommended_roles'])
            ? implode(', ', array_map('trim', $json['recommended_roles']))
            : '',
        'strengths' => !empty($json['strengths']) && is_array($json['strengths'])
            ? implode(', ', array_map('trim', $json['strengths']))
            : '',
        'source' => 'Gemini Analyzer'
    ];
}