<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';
require_once '../includes/job_matcher.php';

requireRole(['partner']);

$user_id = (int) $_SESSION['user_id'];
$jobId = isset($_GET['job_id']) && ctype_digit((string) $_GET['job_id']) ? (int) $_GET['job_id'] : 0;
$refreshAi = isset($_GET['refresh_ai']) && $_GET['refresh_ai'] === '1';

if ($jobId <= 0) {
    header('Location: job_posts.php');
    exit;
}

$jobStmt = $pdo->prepare("\n    SELECT \n        jp.*,\n        pp.company_name,\n        pp.industry_type,\n        pp.website\n    FROM job_posts jp\n    LEFT JOIN partner_profiles pp ON jp.partner_id = pp.user_id\n    WHERE jp.id = ?\n      AND jp.partner_id = ?\n    LIMIT 1\n");
$jobStmt->execute([$jobId, $user_id]);
$job = $jobStmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    header('Location: job_posts.php');
    exit;
}

$courseLabels = [
    'BSEd' => 'Bachelor of Secondary Education',
    'BEEd' => 'Bachelor of Elementary Education',
    'BPEd' => 'Bachelor of Physical Education',
    'BTLEd' => 'Bachelor of Technology and Livelihood Education',
    'BSA' => 'Bachelor of Science in Accountancy',
    'BSBA' => 'Bachelor of Science in Business Administration',
    'BSIT' => 'Bachelor of Science in Information Technology',
    'BSHM' => 'Bachelor of Science in Hospitality Management',
    'BSIndTech' => 'Bachelor of Science in Industrial Technology'
];

function tmShortText($text, $limit = 180)
{
    $text = trim((string) $text);
    if ($text === '') {
        return 'Not provided.';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function tmSafe($value, $fallback = '—')
{
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : $fallback;
}

function tmInitials($first, $last)
{
    $first = trim((string) $first);
    $last = trim((string) $last);
    return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
}

function tmMatchClass($score)
{
    $score = (int) $score;
    if ($score >= 85) {
        return 'match-excellent';
    }
    if ($score >= 70) {
        return 'match-high';
    }
    if ($score >= 55) {
        return 'match-moderate';
    }
    return 'match-low';
}

function tmSplitChips($text, $limit = 8)
{
    $items = preg_split('/[,;\n\r|]+/', (string) $text);
    $clean = [];

    foreach ($items as $item) {
        $item = trim($item);
        if ($item !== '') {
            $clean[] = $item;
        }
    }

    return array_slice(array_values(array_unique($clean)), 0, $limit);
}

$alumniStmt = $pdo->prepare("\n    SELECT \n        u.id AS alumni_id,\n        u.first_name,\n        u.last_name,\n        u.email,\n        u.status AS account_status,\n        ap.student_id,\n        ap.course,\n        ap.major,\n        ap.graduation_year,\n        ap.phone,\n        ap.address,\n        ap.employment_status,\n        ap.bio,\n        ap.skills,\n        ap.preferred_job_titles,\n        ap.career_interests,\n        ap.certifications,\n        ap.work_experience,\n        ap.preferred_location,\n        ap.preferred_work_setup,\n        ap.expected_salary,\n        ap.available_start_date,\n        ap.linkedin_url,\n        ap.portfolio_url,\n        ap.ai_profile_summary,\n        ap.ai_career_tags,\n        ap.profile_photo,\n        a.id AS application_id,\n        a.status AS application_status,\n        a.applied_at\n    FROM users u\n    INNER JOIN alumni_profiles ap ON u.id = ap.user_id\n    LEFT JOIN applications a \n        ON a.alumni_id = u.id\n       AND a.job_post_id = ?\n    WHERE u.role = 'alumni'\n      AND u.status = 'active'\n    ORDER BY u.last_name ASC, u.first_name ASC\n");
$alumniStmt->execute([$jobId]);
$alumniProfiles = $alumniStmt->fetchAll(PDO::FETCH_ASSOC);

$matches = [];

foreach ($alumniProfiles as $profile) {
    $match = getJobRecommendation($pdo, (int) $profile['alumni_id'], $profile, $job, $refreshAi);

    $matches[] = array_merge($profile, [
        'match_score' => (int) ($match['match_score'] ?? 0),
        'match_level' => $match['match_level'] ?? getMatchLevel((int) ($match['match_score'] ?? 0)),
        'ai_reason' => $match['ai_reason'] ?? '',
        'missing_skills' => $match['missing_skills'] ?? '',
        'suggested_action' => $match['suggested_action'] ?? '',
        'match_source' => $match['source'] ?? 'Matcher'
    ]);
}

usort($matches, function ($a, $b) {
    if ((int) $a['match_score'] === (int) $b['match_score']) {
        return strcmp((string) ($a['last_name'] ?? ''), (string) ($b['last_name'] ?? ''));
    }
    return (int) $b['match_score'] <=> (int) $a['match_score'];
});

$topMatches = array_slice($matches, 0, 5);

$invitedMap = [];
if (!empty($topMatches)) {
    $topAlumniIds = array_map(fn($item) => (int) $item['alumni_id'], $topMatches);
    $placeholders = implode(',', array_fill(0, count($topAlumniIds), '?'));

    $inviteStmt = $pdo->prepare("
        SELECT alumni_id, status, created_at
        FROM job_invitations
        WHERE job_post_id = ?
          AND alumni_id IN ($placeholders)
    ");
    $inviteStmt->execute(array_merge([$jobId], $topAlumniIds));

    foreach ($inviteStmt->fetchAll(PDO::FETCH_ASSOC) as $invite) {
        $invitedMap[(int) $invite['alumni_id']] = $invite;
    }
}

$totalCandidates = count($matches);
$bestScore = !empty($topMatches) ? (int) $topMatches[0]['match_score'] : 0;
$strongMatches = count(array_filter($topMatches, fn($item) => (int) $item['match_score'] >= 70));
$alreadyApplied = count(array_filter($topMatches, fn($item) => !empty($item['application_id'])));
$alreadyInvited = count($invitedMap);

$pageTitle = 'Top 5 AI Matches';
$pageSubtitle = 'Review the most suitable alumni candidates for this job post.';
$activePage = 'jobs';

require_once '../includes/dashboard_header.php';
?>

<style>
    .match-console-hero {
        background: radial-gradient(circle at top right, rgba(255,255,255,.18), transparent 32%), linear-gradient(135deg, #6f0000 0%, #8b1111 48%, #2b0505 100%);
        color: #fff;
        border-radius: 28px;
        padding: 28px;
        margin-bottom: 24px;
        box-shadow: 0 24px 60px rgba(91, 0, 0, .22);
        position: relative;
        overflow: hidden;
    }

    .match-console-hero::after {
        content: '';
        position: absolute;
        right: -80px;
        bottom: -90px;
        width: 260px;
        height: 260px;
        border-radius: 999px;
        border: 44px solid rgba(255,255,255,.08);
    }

    .match-console-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255,255,255,.14);
        border: 1px solid rgba(255,255,255,.22);
        color: #fff;
        padding: 8px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .match-console-title {
        font-size: clamp(28px, 4vw, 44px);
        font-weight: 900;
        line-height: 1.05;
        margin: 16px 0 10px;
        letter-spacing: -.04em;
    }

    .match-console-text {
        color: rgba(255,255,255,.82);
        max-width: 760px;
        font-size: 15px;
        line-height: 1.7;
    }

    .match-hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 20px;
    }

    .match-hero-actions a {
        text-decoration: none;
    }

    .match-btn-white {
        background: #fff;
        color: #7b0d0d;
        font-weight: 800;
        border-radius: 14px;
        padding: 11px 16px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 14px 30px rgba(0,0,0,.14);
    }

    .match-btn-ghost {
        color: #fff;
        border: 1px solid rgba(255,255,255,.28);
        background: rgba(255,255,255,.1);
        font-weight: 800;
        border-radius: 14px;
        padding: 11px 16px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .match-stat-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }

    .match-stat-card {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 14px 34px rgba(100,0,0,.07);
    }

    .match-stat-label {
        color: #806c6c;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .08em;
    }

    .match-stat-value {
        color: #2d1515;
        font-size: 30px;
        font-weight: 900;
        margin-top: 8px;
        letter-spacing: -.03em;
    }

    .match-stat-note {
        color: #8a7676;
        font-size: 13px;
        margin-top: 4px;
    }

    .job-context-card,
    .candidate-card,
    .empty-match-card {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 24px;
        box-shadow: 0 18px 44px rgba(100,0,0,.08);
    }

    .job-context-card {
        padding: 22px;
        margin-bottom: 20px;
    }

    .candidate-list {
        display: grid;
        gap: 16px;
    }

    .candidate-card {
        padding: 20px;
        position: relative;
        overflow: hidden;
    }

    .candidate-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 7px;
        height: 100%;
        background: linear-gradient(180deg, #7b0000, #c04b4b);
    }

    .candidate-head {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 14px;
        align-items: start;
    }

    .rank-pill {
        width: 44px;
        height: 44px;
        border-radius: 16px;
        background: #7b0000;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        box-shadow: 0 12px 24px rgba(123,0,0,.18);
    }

    .candidate-avatar {
        width: 54px;
        height: 54px;
        border-radius: 18px;
        background: #fff4f4;
        color: #7b0000;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        border: 1px solid #efd5d5;
        flex: 0 0 54px;
        overflow: hidden;
    }

    .candidate-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .candidate-name {
        color: #2d1515;
        font-weight: 900;
        font-size: 18px;
        margin-bottom: 4px;
    }

    .candidate-meta {
        color: #806c6c;
        font-size: 13px;
        line-height: 1.6;
    }

    .score-box {
        min-width: 145px;
        text-align: right;
    }

    .score-number {
        color: #7b0000;
        font-size: 32px;
        line-height: 1;
        font-weight: 950;
        letter-spacing: -.04em;
    }

    .score-label {
        color: #806c6c;
        font-size: 12px;
        font-weight: 800;
        margin-top: 4px;
    }

    .match-bar {
        height: 9px;
        background: #f2e6e6;
        border-radius: 999px;
        overflow: hidden;
        margin-top: 10px;
    }

    .match-bar > span {
        height: 100%;
        display: block;
        border-radius: inherit;
        background: #7b0000;
    }

    .match-excellent .match-bar > span { background: linear-gradient(90deg, #0f8f4d, #4ccb83); }
    .match-high .match-bar > span { background: linear-gradient(90deg, #7b0000, #d54b4b); }
    .match-moderate .match-bar > span { background: linear-gradient(90deg, #c78314, #f0b04d); }
    .match-low .match-bar > span { background: linear-gradient(90deg, #777, #aaa); }

    .candidate-body {
        display: grid;
        grid-template-columns: 1.25fr .75fr;
        gap: 16px;
        margin-top: 16px;
    }

    .insight-box {
        background: #fff8f8;
        border: 1px solid #f0dddd;
        border-radius: 18px;
        padding: 14px;
    }

    .insight-title {
        color: #7b0000;
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .08em;
        margin-bottom: 8px;
    }

    .chip-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    .mini-chip {
        background: #fff;
        color: #5e3333;
        border: 1px solid #edd6d6;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 12px;
        font-weight: 700;
    }

    .status-pill {
        border-radius: 999px;
        padding: 7px 11px;
        font-size: 12px;
        font-weight: 850;
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }

    .status-applied {
        color: #0f6e3c;
        background: #e9f8f0;
        border: 1px solid #c8edd8;
    }

    .status-waiting {
        color: #7b4a00;
        background: #fff8e8;
        border: 1px solid #f1dfad;
    }

    .candidate-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 16px;
    }

    .candidate-action-primary,
    .candidate-action-invite,
    .candidate-action-muted {
        border-radius: 13px;
        padding: 10px 13px;
        font-size: 13px;
        font-weight: 850;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .candidate-action-primary {
        background: linear-gradient(135deg, var(--maroon, #d08c0c), var(--dark-maroon, #be7f09));
        color: #fff;
        border: 0;
    }

    .candidate-action-invite {
        background: linear-gradient(135deg, #d08c0c, #be7f09);
        color: #fff;
        border: 0;
        cursor: pointer;
        box-shadow: 0 12px 26px rgba(208, 140, 12, .22);
    }

    .candidate-action-invite:hover {
        transform: translateY(-1px);
    }

    .candidate-action-muted {
        background: #fff;
        color: #6b5353;
        border: 1px solid #ead7d7;
    }

    .empty-match-card {
        padding: 34px;
        text-align: center;
        color: #806c6c;
    }

    @media (max-width: 992px) {
        .match-stat-grid,
        .candidate-body {
            grid-template-columns: 1fr;
        }

        .candidate-head {
            grid-template-columns: auto minmax(0, 1fr);
        }

        .score-box {
            grid-column: 1 / -1;
            text-align: left;
        }
    }

    @media (max-width: 576px) {
        .match-console-hero {
            padding: 22px;
            border-radius: 22px;
        }

        .match-stat-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<?php if (!empty($_SESSION['flash_message'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?> rounded-4 mb-4">
        <?= htmlspecialchars($_SESSION['flash_message']) ?>
    </div>
    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<div class="match-console-hero">
    <div class="match-console-kicker">AI Recruitment Filter</div>
    <div class="match-console-title">Top 5 alumni matches for <?= htmlspecialchars($job['title']) ?></div>
    <div class="match-console-text">
        The system ranks active alumni profiles against this job post using course alignment, skills, preferred roles,
        career interests, location, work setup, and job requirements. The company can review the strongest matches first.
    </div>
    <div class="match-hero-actions">
        <a href="job_posts.php" class="match-btn-white">← Back to Job Posts</a>
        <a href="top_matches.php?job_id=<?= (int) $jobId ?>&refresh_ai=1" class="match-btn-ghost" data-loader-text="Refreshing AI scores… this can take a while." onclick="return confirm('Refresh AI scores for all alumni? This may take longer if external AI is enabled.');">↻ Refresh AI Scan</a>
        <a href="applicants.php?job_id=<?= (int) $jobId ?>" class="match-btn-ghost">View Applicants</a>
    </div>
</div>

<div class="match-stat-grid">
    <div class="match-stat-card">
        <div class="match-stat-label">Candidates Scanned</div>
        <div class="match-stat-value"><?= (int) $totalCandidates ?></div>
        <div class="match-stat-note">Active alumni profiles</div>
    </div>
    <div class="match-stat-card">
        <div class="match-stat-label">Best Match</div>
        <div class="match-stat-value"><?= (int) $bestScore ?>%</div>
        <div class="match-stat-note">Highest suitability score</div>
    </div>
    <div class="match-stat-card">
        <div class="match-stat-label">Strong Matches</div>
        <div class="match-stat-value"><?= (int) $strongMatches ?></div>
        <div class="match-stat-note">Top 5 with 70% or higher</div>
    </div>
    <div class="match-stat-card">
        <div class="match-stat-label">Already Applied</div>
        <div class="match-stat-value"><?= (int) $alreadyApplied ?></div>
        <div class="match-stat-note">Top 5 with application records</div>
    </div>
    <div class="match-stat-card">
        <div class="match-stat-label">Invited</div>
        <div class="match-stat-value"><?= (int) $alreadyInvited ?></div>
        <div class="match-stat-note">Top 5 already invited</div>
    </div>
</div>

<div class="job-context-card">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div class="small fw-bold text-uppercase" style="color:#7b0000; letter-spacing:.08em;">Job Context</div>
            <h3 class="mt-2 mb-1" style="font-weight:900; color:#2d1515;"><?= htmlspecialchars($job['title']) ?></h3>
            <div class="text-muted small">
                <?= htmlspecialchars(tmSafe($job['company_name'] ?? '', 'Your company')) ?>
                <?php if (!empty($job['employment_type'])): ?> • <?= htmlspecialchars($job['employment_type']) ?><?php endif; ?>
                <?php if (!empty($job['location'])): ?> • <?= htmlspecialchars($job['location']) ?><?php endif; ?>
                <?php if (!empty($job['deadline'])): ?> • Deadline <?= date('M j, Y', strtotime($job['deadline'])) ?><?php endif; ?>
            </div>
        </div>
        <span class="status-pill <?= ($job['status'] ?? '') === 'Open' ? 'status-applied' : 'status-waiting' ?>">
            <?= htmlspecialchars($job['status'] ?? 'Open') ?>
        </span>
    </div>
    <div class="mt-3 text-muted"><?= nl2br(htmlspecialchars(tmShortText($job['description'] ?? '', 260))) ?></div>
</div>

<?php if (empty($topMatches)): ?>
    <div class="empty-match-card">
        <h4 class="fw-bold mb-2">No alumni profiles available yet</h4>
        <div>Once active alumni complete their profiles, the AI matching list will appear here.</div>
    </div>
<?php else: ?>
    <div class="candidate-list">
        <?php foreach ($topMatches as $index => $candidate): ?>
            <?php
                $score = (int) $candidate['match_score'];
                $displayCourse = $courseLabels[$candidate['course'] ?? ''] ?? tmSafe($candidate['course'] ?? '');
                $skills = tmSplitChips($candidate['skills'] ?? '');
                $careerTags = tmSplitChips(($candidate['preferred_job_titles'] ?? '') . ',' . ($candidate['career_interests'] ?? '') . ',' . ($candidate['ai_career_tags'] ?? ''), 6);
                $hasApplied = !empty($candidate['application_id']);
                $inviteInfo = $invitedMap[(int) $candidate['alumni_id']] ?? null;
                $hasInvite = !empty($inviteInfo);
            ?>
            <div class="candidate-card <?= htmlspecialchars(tmMatchClass($score)) ?>">
                <div class="candidate-head">
                    <div class="rank-pill">#<?= $index + 1 ?></div>

                    <div class="d-flex gap-3 align-items-start">
                        <?php $candPhotoUrl = profilePhotoUrl($candidate['profile_photo'] ?? '', '../'); ?>
                        <div class="candidate-avatar">
                            <?php if ($candPhotoUrl): ?>
                                <img src="<?= htmlspecialchars($candPhotoUrl) ?>" alt="" loading="lazy">
                            <?php else: ?>
                            <?= htmlspecialchars(tmInitials($candidate['first_name'] ?? '', $candidate['last_name'] ?? '')) ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="candidate-name">
                                <?= htmlspecialchars(trim(($candidate['first_name'] ?? '') . ' ' . ($candidate['last_name'] ?? ''))) ?>
                            </div>
                            <div class="candidate-meta">
                                <?= htmlspecialchars($displayCourse) ?>
                                <?php if (!empty($candidate['major'])): ?> • <?= htmlspecialchars($candidate['major']) ?><?php endif; ?>
                                <?php if (!empty($candidate['graduation_year'])): ?> • Class of <?= htmlspecialchars($candidate['graduation_year']) ?><?php endif; ?><br>
                                Employment: <?= htmlspecialchars(tmSafe($candidate['employment_status'] ?? '')) ?>
                                <?php if (!empty($candidate['preferred_location'])): ?> • Prefers <?= htmlspecialchars($candidate['preferred_location']) ?><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="score-box">
                        <div class="score-number"><?= $score ?>%</div>
                        <div class="score-label"><?= htmlspecialchars($candidate['match_level'] ?? getMatchLevel($score)) ?> Match</div>
                        <div class="match-bar"><span style="width: <?= max(0, min(100, $score)) ?>%;"></span></div>
                    </div>
                </div>

                <div class="candidate-body">
                    <div class="insight-box">
                        <div class="insight-title">AI Match Insight</div>
                        <div class="text-muted"><?= htmlspecialchars(tmSafe($candidate['ai_reason'] ?? '', 'Matched using alumni profile and job requirements.')) ?></div>

                        <?php if (!empty($skills)): ?>
                            <div class="chip-row">
                                <?php foreach ($skills as $skill): ?>
                                    <span class="mini-chip"><?= htmlspecialchars($skill) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="insight-box">
                        <div class="insight-title">Recruiter Notes</div>
                        <div class="mb-2">
                            <?php if ($hasApplied): ?>
                                <span class="status-pill status-applied">Applied • <?= htmlspecialchars($candidate['application_status'] ?? 'Pending') ?></span>
                            <?php else: ?>
                                <span class="status-pill status-waiting">Not yet applied</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">
                            <?= htmlspecialchars(tmSafe($candidate['suggested_action'] ?? '', 'Review profile fit and wait for application.')) ?>
                        </div>

                        <?php if (!empty($careerTags)): ?>
                            <div class="chip-row">
                                <?php foreach ($careerTags as $tag): ?>
                                    <span class="mini-chip"><?= htmlspecialchars($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($candidate['missing_skills'])): ?>
                    <div class="mt-3 small text-muted">
                        <strong style="color:#7b0000;">Possible gaps:</strong>
                        <?= htmlspecialchars($candidate['missing_skills']) ?>
                    </div>
                <?php endif; ?>

                <div class="candidate-actions">
                    <?php if ($hasApplied): ?>
                        <a class="candidate-action-primary" href="applicants.php?job_id=<?= (int) $jobId ?>&search=<?= urlencode(trim(($candidate['first_name'] ?? '') . ' ' . ($candidate['last_name'] ?? ''))) ?>">
                            Review Application
                        </a>
                    <?php elseif ($hasInvite): ?>
                        <span class="candidate-action-muted">
                            Invited <?= !empty($inviteInfo['created_at']) ? 'on ' . date('M j, Y', strtotime($inviteInfo['created_at'])) : '' ?>
                        </span>
                        <form method="POST" action="invite_alumni.php" onsubmit="return confirm('Resend invitation to this alumni?');" class="m-0">
                            <input type="hidden" name="job_id" value="<?= (int) $jobId ?>">
                            <input type="hidden" name="alumni_id" value="<?= (int) $candidate['alumni_id'] ?>">
                            <input type="hidden" name="match_score" value="<?= (int) $score ?>">
                            <button type="submit" class="candidate-action-invite">Resend Invite</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" action="invite_alumni.php" onsubmit="return confirm('Send an invitation to apply for this job?');" class="m-0">
                            <input type="hidden" name="job_id" value="<?= (int) $jobId ?>">
                            <input type="hidden" name="alumni_id" value="<?= (int) $candidate['alumni_id'] ?>">
                            <input type="hidden" name="match_score" value="<?= (int) $score ?>">
                            <button type="submit" class="candidate-action-invite">Invite to Apply</button>
                        </form>
                    <?php endif; ?>
                    <span class="candidate-action-muted">AI Match Result</span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once '../includes/dashboard_footer.php'; ?>
