<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];
$errors = [];
$setupMissing = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $invitationId = $_POST['invitation_id'] ?? '';

    if ($action === 'dismiss' && ctype_digit((string) $invitationId)) {
        try {
            $dismissStmt = $pdo->prepare("\n                UPDATE job_invitations\n                SET status = 'Dismissed'\n                WHERE id = ?\n                  AND alumni_id = ?\n                  AND status IN ('Sent', 'Viewed')\n            ");
            $dismissStmt->execute([$invitationId, $user_id]);

            $_SESSION['flash_message'] = 'Job invitation dismissed.';
            $_SESSION['flash_type'] = 'success';
            header('Location: job_invitations.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Unable to update invitation status.';
        }
    }
}

try {
    $markViewedStmt = $pdo->prepare("\n        UPDATE job_invitations\n        SET status = 'Viewed'\n        WHERE alumni_id = ?\n          AND status = 'Sent'\n    ");
    $markViewedStmt->execute([$user_id]);

    $invitationStmt = $pdo->prepare("\n        SELECT\n            ji.*,\n            jp.title,\n            jp.description,\n            jp.requirements,\n            jp.location,\n            jp.employment_type,\n            jp.salary_range,\n            jp.deadline,\n            jp.status AS job_status,\n            pp.company_name,\n            pp.industry_type,\n            pp.website,\n            a.id AS application_id,\n            a.status AS application_status,\n            a.applied_at\n        FROM job_invitations ji\n        INNER JOIN job_posts jp ON ji.job_post_id = jp.id\n        INNER JOIN partner_profiles pp ON ji.partner_id = pp.user_id\n        LEFT JOIN applications a\n            ON a.job_post_id = ji.job_post_id\n           AND a.alumni_id = ji.alumni_id\n        WHERE ji.alumni_id = ?\n        ORDER BY ji.created_at DESC, ji.id DESC\n    ");
    $invitationStmt->execute([$user_id]);
    $invitations = $invitationStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $setupMissing = true;
    $invitations = [];
}

$totalInvitations = count($invitations);
$newInvitations = 0;
$openInvitations = 0;
$appliedInvitations = 0;
$dismissedInvitations = 0;

foreach ($invitations as $invite) {
    $isApplied = !empty($invite['application_id']) || (($invite['status'] ?? '') === 'Applied');
    $isDismissed = (($invite['status'] ?? '') === 'Dismissed');
    $isJobOpen = (($invite['job_status'] ?? '') === 'Open') && (empty($invite['deadline']) || strtotime($invite['deadline']) >= strtotime(date('Y-m-d')));

    if (($invite['status'] ?? '') === 'Viewed') {
        $newInvitations++;
    }

    if ($isApplied) {
        $appliedInvitations++;
    }

    if ($isDismissed) {
        $dismissedInvitations++;
    }

    if ($isJobOpen && !$isApplied && !$isDismissed) {
        $openInvitations++;
    }
}

function invitationStatusMeta(array $invite): array
{
    $isApplied = !empty($invite['application_id']) || (($invite['status'] ?? '') === 'Applied');
    $isDismissed = (($invite['status'] ?? '') === 'Dismissed');
    $isJobOpen = (($invite['job_status'] ?? '') === 'Open') && (empty($invite['deadline']) || strtotime($invite['deadline']) >= strtotime(date('Y-m-d')));

    if ($isApplied) {
        return ['Applied', 'success'];
    }

    if ($isDismissed) {
        return ['Dismissed', 'muted'];
    }

    if (!$isJobOpen) {
        return ['Closed', 'danger'];
    }

    return ['Invited', 'gold'];
}

function invitationDeadlineText(?string $deadline): string
{
    if (empty($deadline)) {
        return 'No deadline';
    }

    $today = new DateTime(date('Y-m-d'));
    $deadlineDate = new DateTime($deadline);
    $diff = (int) $today->diff($deadlineDate)->format('%r%a');

    if ($diff < 0) {
        return 'Expired';
    }

    if ($diff === 0) {
        return 'Deadline today';
    }

    return $diff . ' day' . ($diff === 1 ? '' : 's') . ' left';
}

$pageTitle = 'Job Invitations';
$pageSubtitle = 'Review company invitations and apply to roles selected for your profile.';
$activePage = 'jobs';

require_once '../includes/dashboard_header.php';
?>

<style>
    .invitation-hero {
        position: relative;
        overflow: hidden;
        border-radius: 26px;
        padding: 30px;
        background:
            radial-gradient(circle at top right, rgba(128, 0, 0, .16), transparent 32%),
            linear-gradient(135deg, #800000 0%, #4d0000 100%);
        color: #fff;
        box-shadow: 0 22px 60px rgba(128, 0, 0, .20);
        margin-bottom: 22px;
    }

    .invitation-hero::after {
        content: "";
        position: absolute;
        right: -90px;
        top: -90px;
        width: 240px;
        height: 240px;
        border-radius: 50%;
        background: rgba(255,255,255,.08);
    }

    .invitation-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1fr) 300px;
        gap: 24px;
        align-items: center;
    }

    .invitation-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255,255,255,.14);
        border: 1px solid rgba(255,255,255,.18);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .4px;
        text-transform: uppercase;
    }

    .invitation-title {
        font-size: clamp(28px, 4vw, 42px);
        font-weight: 900;
        letter-spacing: -1px;
        margin-top: 14px;
        margin-bottom: 10px;
    }

    .invitation-copy {
        color: rgba(255,255,255,.82);
        max-width: 720px;
        line-height: 1.7;
        margin: 0;
    }

    .invitation-pulse-card {
        border-radius: 22px;
        padding: 22px;
        background: rgba(255,255,255,.12);
        border: 1px solid rgba(255,255,255,.18);
        backdrop-filter: blur(14px);
    }

    .invitation-pulse-value {
        font-size: 42px;
        font-weight: 900;
        line-height: 1;
    }

    .invitation-pulse-label {
        color: rgba(255,255,255,.74);
        font-size: 13px;
        margin-top: 8px;
    }

    .invitation-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 22px;
    }

    .invitation-stat-card {
        background: #fff;
        border: 1px solid #f1dfdf;
        border-radius: 20px;
        padding: 20px;
        box-shadow: 0 16px 36px rgba(128,0,0,.06);
    }

    .invitation-stat-label {
        font-size: 12px;
        color: #7a6565;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    .invitation-stat-value {
        margin-top: 8px;
        font-size: 30px;
        font-weight: 900;
        color: #800000;
    }

    .invitation-list {
        display: grid;
        gap: 16px;
    }

    .invitation-card {
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 24px;
        padding: 22px;
        box-shadow: 0 18px 45px rgba(128,0,0,.07);
    }

    .invitation-card-top {
        display: flex;
        justify-content: space-between;
        gap: 18px;
        align-items: flex-start;
    }

    .company-mark {
        width: 54px;
        height: 54px;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #800000, #4d0000);
        color: #fff;
        font-weight: 900;
        flex: 0 0 54px;
        box-shadow: 0 12px 24px rgba(128,0,0,.16);
    }

    .invitation-headline {
        display: flex;
        gap: 14px;
        align-items: flex-start;
    }

    .invitation-job-title {
        font-size: 20px;
        font-weight: 900;
        color: #271616;
        margin: 0;
    }

    .invitation-company {
        color: #756262;
        margin-top: 4px;
        font-weight: 600;
    }

    .badge-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }

    .invite-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        background: #fff6f6;
        color: #7d2828;
        border: 1px solid #f0d6d6;
    }

    .invite-badge.success {
        background: #ecfdf3;
        color: #166534;
        border-color: #bbf7d0;
    }

    .invite-badge.danger {
        background: #fef2f2;
        color: #991b1b;
        border-color: #fecaca;
    }

    .invite-badge.muted {
        background: #f3f4f6;
        color: #4b5563;
        border-color: #e5e7eb;
    }

    .invite-badge.gold {
        background: #fff8e8;
        color: #8a5700;
        border-color: #f1d79d;
    }

    .match-score-ring {
        min-width: 92px;
        border-radius: 20px;
        padding: 14px;
        background: linear-gradient(135deg, #fff8e8, #fff1cf);
        border: 1px solid #f1d79d;
        text-align: center;
    }

    .match-score-ring strong {
        display: block;
        font-size: 26px;
        color: #9f6505;
        line-height: 1;
    }

    .match-score-ring span {
        display: block;
        margin-top: 6px;
        color: #8a6a2a;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .4px;
    }

    .invite-message {
        margin-top: 18px;
        border-radius: 18px;
        background: #faf7f7;
        border: 1px solid #f0dddd;
        padding: 16px;
        color: #4b3a3a;
        line-height: 1.65;
    }

    .invite-actions {
        margin-top: 18px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
    }

    .btn-gold {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 14px;
        padding: 10px 15px;
        background: linear-gradient(135deg, #d08c0c, #be7f09);
        color: #fff !important;
        border: 1px solid #be7f09;
        text-decoration: none;
        font-weight: 800;
        box-shadow: 0 12px 24px rgba(208, 140, 12, .18);
    }

    .btn-soft-outline {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 14px;
        padding: 10px 15px;
        background: #fff;
        color: #5a3333 !important;
        border: 1px solid #ead6d6;
        text-decoration: none;
        font-weight: 800;
    }

    .btn-muted-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        padding: 10px 15px;
        background: #f8f8f8;
        color: #5b5b5b;
        border: 1px solid #e7e7e7;
        font-weight: 800;
    }

    .empty-invitation-state {
        text-align: center;
        background: #fff;
        border: 1px solid #f0dddd;
        border-radius: 26px;
        padding: 46px 24px;
        box-shadow: 0 18px 45px rgba(128,0,0,.06);
    }

    .empty-invitation-icon {
        width: 74px;
        height: 74px;
        border-radius: 24px;
        margin: 0 auto 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fff6f6;
        color: #800000;
        font-size: 28px;
    }

    @media (max-width: 992px) {
        .invitation-hero-grid,
        .invitation-stat-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .invitation-card-top {
            flex-direction: column;
        }

        .match-score-ring {
            width: 100%;
        }
    }
</style>

<div class="invitation-hero fade-in">
    <div class="invitation-hero-grid">
        <div>
            <span class="invitation-eyebrow">Company Invitations</span>
            <h1 class="invitation-title">Jobs selected for your profile</h1>
            <p class="invitation-copy">
                Review job opportunities where partner companies identified you as a strong candidate match.
                You can view the job details, apply, or dismiss invitations that are not a fit.
            </p>
        </div>

        <div class="invitation-pulse-card">
            <div class="invitation-pulse-value"><?= (int) $openInvitations ?></div>
            <div class="invitation-pulse-label">open invitations ready for review</div>
        </div>
    </div>
</div>

<?php if (!empty($_SESSION['flash_message'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?> rounded-4 mb-4">
        <?= htmlspecialchars($_SESSION['flash_message']) ?>
    </div>
    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger rounded-4 mb-4">
        <?php foreach ($errors as $error): ?>
            <div><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($setupMissing): ?>
    <div class="empty-invitation-state">
        <div class="empty-invitation-icon">!</div>
        <h3>Job invitation table is not ready</h3>
        <p class="text-muted mb-0">
            Please run the <strong>job_invitations_table.sql</strong> patch first before using this page.
        </p>
    </div>
<?php else: ?>
    <div class="invitation-stat-grid fade-in">
        <div class="invitation-stat-card">
            <div class="invitation-stat-label">Total Invitations</div>
            <div class="invitation-stat-value"><?= (int) $totalInvitations ?></div>
        </div>

        <div class="invitation-stat-card">
            <div class="invitation-stat-label">Open to Apply</div>
            <div class="invitation-stat-value"><?= (int) $openInvitations ?></div>
        </div>

        <div class="invitation-stat-card">
            <div class="invitation-stat-label">Applications Sent</div>
            <div class="invitation-stat-value"><?= (int) $appliedInvitations ?></div>
        </div>

        <div class="invitation-stat-card">
            <div class="invitation-stat-label">Dismissed</div>
            <div class="invitation-stat-value"><?= (int) $dismissedInvitations ?></div>
        </div>
    </div>

    <?php if (empty($invitations)): ?>
        <div class="empty-invitation-state fade-in">
            <div class="empty-invitation-icon">@</div>
            <h3>No company invitations yet</h3>
            <p class="text-muted mb-4">
                When a partner company invites you to apply, the opportunity will appear here.
            </p>
            <a href="recommended_jobs.php" class="btn-gold">Browse Recommended Jobs</a>
        </div>
    <?php else: ?>
        <div class="invitation-list fade-in">
            <?php foreach ($invitations as $invite): ?>
                <?php
                    [$statusLabel, $statusClass] = invitationStatusMeta($invite);
                    $companyInitial = strtoupper(substr((string) ($invite['company_name'] ?? 'C'), 0, 1));
                    $deadlineText = invitationDeadlineText($invite['deadline'] ?? null);
                    $canApply = $statusLabel === 'Invited';
                ?>

                <div class="invitation-card">
                    <div class="invitation-card-top">
                        <div class="invitation-headline">
                            <div class="company-mark"><?= htmlspecialchars($companyInitial) ?></div>
                            <div>
                                <h2 class="invitation-job-title"><?= htmlspecialchars($invite['title'] ?? 'Untitled Job') ?></h2>
                                <div class="invitation-company">
                                    <?= htmlspecialchars($invite['company_name'] ?? 'Company') ?>
                                    <?php if (!empty($invite['industry_type'])): ?>
                                        &middot; <?= htmlspecialchars($invite['industry_type']) ?>
                                    <?php endif; ?>
                                </div>

                                <div class="badge-row">
                                    <span class="invite-badge <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                    <?php if (!empty($invite['employment_type'])): ?>
                                        <span class="invite-badge"><?= htmlspecialchars($invite['employment_type']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($invite['location'])): ?>
                                        <span class="invite-badge"><?= htmlspecialchars($invite['location']) ?></span>
                                    <?php endif; ?>
                                    <span class="invite-badge <?= $deadlineText === 'Expired' ? 'danger' : 'gold' ?>"><?= htmlspecialchars($deadlineText) ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="match-score-ring">
                            <strong><?= (int) ($invite['match_score'] ?? 0) ?>%</strong>
                            <span>Match Score</span>
                        </div>
                    </div>

                    <?php if (!empty($invite['message'])): ?>
                        <div class="invite-message">
                            <?= nl2br(htmlspecialchars($invite['message'])) ?>
                        </div>
                    <?php else: ?>
                        <div class="invite-message">
                            This company invited you because your profile looks aligned with this job opportunity.
                        </div>
                    <?php endif; ?>

                    <div class="invite-actions">
                        <a href="view_job.php?id=<?= (int) $invite['job_post_id'] ?>" class="btn-soft-outline">View Job Details</a>

                        <?php if (!empty($invite['application_id'])): ?>
                            <a href="applications.php" class="btn-gold">View Application</a>
                        <?php elseif ($canApply): ?>
                            <a href="apply_job.php?id=<?= (int) $invite['job_post_id'] ?>" class="btn-gold">Apply Now</a>
                            <form method="POST" action="" class="d-inline">
                                <input type="hidden" name="action" value="dismiss">
                                <input type="hidden" name="invitation_id" value="<?= (int) $invite['id'] ?>">
                                <button type="submit" class="btn-muted-action" onclick="return confirm('Dismiss this job invitation?');">Dismiss</button>
                            </form>
                        <?php else: ?>
                            <span class="btn-muted-action"><?= htmlspecialchars($statusLabel) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once '../includes/dashboard_footer.php'; ?>
