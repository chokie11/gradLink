<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Load alumni profile
|--------------------------------------------------------------------------
*/
$profileStmt = $pdo->prepare("
    SELECT ap.*, u.first_name, u.last_name, u.email
    FROM alumni_profiles ap
    INNER JOIN users u ON ap.user_id = u.id
    WHERE ap.user_id = ?
    LIMIT 1
");
$profileStmt->execute([$user_id]);
$alumniProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Optional simple matching logic
|--------------------------------------------------------------------------
| For now, we’ll compute a simple “match hint” based on course/major keywords
| found in title/description/requirements. This is not yet full AI matching,
| but it gives a more realistic Phase 2 experience.
|--------------------------------------------------------------------------
*/
$course = strtolower(trim($alumniProfile['course'] ?? ''));
$major = strtolower(trim($alumniProfile['major'] ?? ''));

/*
|--------------------------------------------------------------------------
| Load open jobs
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT 
        jp.*,
        pp.company_name,
        pp.industry_type,
        pp.website
    FROM job_posts jp
    INNER JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    WHERE jp.status = 'Open'
      AND (jp.deadline IS NULL OR jp.deadline >= CURDATE())
    ORDER BY 
        CASE WHEN jp.deadline IS NULL THEN 1 ELSE 0 END,
        jp.deadline ASC,
        jp.created_at DESC
");
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Load already applied job IDs
|--------------------------------------------------------------------------
*/
$appliedStmt = $pdo->prepare("
    SELECT job_post_id
    FROM applications
    WHERE alumni_id = ?
");
$appliedStmt->execute([$user_id]);
$appliedJobIds = $appliedStmt->fetchAll(PDO::FETCH_COLUMN);


/*
|--------------------------------------------------------------------------
| Course label mapping
|--------------------------------------------------------------------------
*/
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

$displayCourse = $courseLabels[$alumniProfile['course'] ?? ''] ?? ($alumniProfile['course'] ?? '—');

/*
|--------------------------------------------------------------------------
| Simple match scoring
|--------------------------------------------------------------------------
*/
foreach ($jobs as &$job) {
    $score = 40; // base score
    $searchText = strtolower(
        ($job['title'] ?? '') . ' ' .
        ($job['description'] ?? '') . ' ' .
        ($job['requirements'] ?? '') . ' ' .
        ($job['industry_type'] ?? '')
    );

    if ($course !== '' && strpos($searchText, $course) !== false) {
        $score += 25;
    }

    if ($major !== '' && strpos($searchText, $major) !== false) {
        $score += 25;
    }

    if (
        $course === 'bsit' && (
            strpos($searchText, 'it') !== false ||
            strpos($searchText, 'information technology') !== false ||
            strpos($searchText, 'web') !== false ||
            strpos($searchText, 'developer') !== false ||
            strpos($searchText, 'technical') !== false
        )
    ) {
        $score += 20;
    }

    if (
        $course === 'bsba' && (
            strpos($searchText, 'marketing') !== false ||
            strpos($searchText, 'sales') !== false ||
            strpos($searchText, 'business') !== false
        )
    ) {
        $score += 20;
    }

    if (
        $course === 'bsa' && (
            strpos($searchText, 'accounting') !== false ||
            strpos($searchText, 'finance') !== false ||
            strpos($searchText, 'bookkeeping') !== false
        )
    ) {
        $score += 20;
    }

    if (
        $course === 'bshm' && (
            strpos($searchText, 'hospitality') !== false ||
            strpos($searchText, 'hotel') !== false ||
            strpos($searchText, 'restaurant') !== false ||
            strpos($searchText, 'tourism') !== false
        )
    ) {
        $score += 20;
    }

    if ($course === 'beed' || $course === 'bsed' || $course === 'bped' || $course === 'btled') {
        if (
            strpos($searchText, 'teacher') !== false ||
            strpos($searchText, 'education') !== false ||
            strpos($searchText, 'school') !== false ||
            strpos($searchText, 'instructor') !== false
        ) {
            $score += 20;
        }
    }

    if ($score > 100) {
        $score = 100;
    }

    $job['match_score'] = $score;
}
unset($job);

/*
|--------------------------------------------------------------------------
| Sort by match score first, then deadline
|--------------------------------------------------------------------------
*/
usort($jobs, function ($a, $b) {
    if ($a['match_score'] === $b['match_score']) {
        return strcmp((string) ($a['deadline'] ?? '9999-12-31'), (string) ($b['deadline'] ?? '9999-12-31'));
    }
    return $b['match_score'] <=> $a['match_score'];
});



$stmt = $pdo->prepare("
    SELECT 
        a.*,
        jp.title,
        jp.location,
        jp.employment_type,
        jp.salary_range,
        jp.deadline,
        pp.company_name
    FROM applications a
    INNER JOIN job_posts jp ON a.job_post_id = jp.id
    INNER JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    WHERE a.alumni_id = ?
    ORDER BY a.applied_at DESC
");
$stmt->execute([$user_id]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalApplications = count($applications);

$pageTitle = 'Recommended Jobs';
$pageSubtitle = 'Explore real job opportunities matched to your profile.';
$activePage = 'jobs';

require_once '../includes/dashboard_header.php';
?>

<div class="hero-card">
    <div class="hero-grid">
        <div>
            <span class="badge-soft">Live Opportunities</span>
            <div class="hero-title mt-3">Recommended jobs for you</div>
            <div class="hero-text">
                These job posts are currently open and sorted using a simple profile-based matching logic.
                As the system grows, this page can later support skills-based and AI-assisted recommendations.
            </div>

            <div class="hero-actions">
                <a href="applications.php" class="btn-maroon">My Applications</a>
                <a href="dashboard.php" class="btn-light-outline">Back to Dashboard</a>
            </div>
        </div>

        <div class="progress-card">
            <div class="label">Available Open Jobs</div>
            <div class="value"><?= count($jobs) ?></div>
            <div class="small">
                Based on your current profile:
                <strong><?= htmlspecialchars($displayCourse) ?></strong>
                <?= !empty($alumniProfile['major']) ? ' • ' . htmlspecialchars($alumniProfile['major']) : '' ?>
            </div>
        </div>
    </div>
</div>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">Open Opportunities</div>
        <div class="stat-value"><?= count($jobs) ?></div>
        <div class="stat-note">Currently available jobs</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Best Match</div>
        <div class="stat-value"><?= !empty($jobs) ? $jobs[0]['match_score'] . '%' : '0%' ?></div>
        <div class="stat-note">Highest current match</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">My Course</div>
        <div class="stat-value" style="font-size:18px;"><?= htmlspecialchars($alumniProfile['course'] ?? '—') ?></div>
        <div class="stat-note"><?= htmlspecialchars($alumniProfile['major'] ?? 'No major selected') ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Applications Sent</div>
        <div class="stat-value"><?= $totalApplications ?></div>
        <div class="stat-note">Total applications submitted</div>
    </div>
</div>

<div class="panel-card">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="panel-title mb-0">Job Opportunities</div>
        <span class="badge-soft">Sorted by match score</span>
    </div>

    <?php if (empty($jobs)): ?>
        <div class="text-muted">
            There are no open job posts available right now.
        </div>
    <?php else: ?>
        <div class="quick-action-list">
            <?php foreach ($jobs as $job): ?>
                <div class="quick-action" style="display:block;">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <strong><?= htmlspecialchars($job['title']) ?></strong>
                            <div class="text-muted small mt-1">
                                <?= htmlspecialchars($job['company_name'] ?? 'Company') ?>
                                <?php if (!empty($job['location'])): ?>
                                    • <?= htmlspecialchars($job['location']) ?>
                                <?php endif; ?>
                                <?php if (!empty($job['employment_type'])): ?>
                                    • <?= htmlspecialchars($job['employment_type']) ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <span class="badge-soft"><?= htmlspecialchars($job['match_score']) ?>% Match</span>
                    </div>

                    <div class="small mt-2" style="color: var(--maroon); font-weight:600;">
                        <?php if (!empty($job['salary_range'])): ?>
                            Salary: <?= htmlspecialchars($job['salary_range']) ?>
                        <?php else: ?>
                            Salary: Not specified
                        <?php endif; ?>

                        <?php if (!empty($job['deadline'])): ?>
                            &nbsp; • &nbsp; Deadline: <?= date('F j, Y', strtotime($job['deadline'])) ?>
                        <?php endif; ?>
                    </div>

                    <div class="mt-3 text-muted">
                        <?= nl2br(htmlspecialchars(mb_strimwidth($job['description'], 0, 240, '...'))) ?>
                    </div>

                    <div class="d-flex gap-2 flex-wrap mt-3">
                        <a href="view_job.php?id=<?= $job['id'] ?>" class="btn-light-outline">View Details</a>

                        <?php if (in_array($job['id'], $appliedJobIds)): ?>
                            <a href="applications.php" class="btn-maroon">Already Applied</a>
                        <?php else: ?>
                            <a href="apply_job.php?id=<?= $job['id'] ?>" class="btn-maroon">Apply Now</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>