<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['partner']);

$partnerId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: job_posts.php');
    exit;
}

$jobId = isset($_POST['job_id']) && ctype_digit((string) $_POST['job_id']) ? (int) $_POST['job_id'] : 0;
$alumniId = isset($_POST['alumni_id']) && ctype_digit((string) $_POST['alumni_id']) ? (int) $_POST['alumni_id'] : 0;
$matchScore = isset($_POST['match_score']) && is_numeric($_POST['match_score']) ? (int) $_POST['match_score'] : 0;
$matchScore = max(0, min(100, $matchScore));

$redirectUrl = 'top_matches.php' . ($jobId > 0 ? '?job_id=' . $jobId : '');

if ($jobId <= 0 || $alumniId <= 0) {
    $_SESSION['flash_message'] = 'Invalid invitation request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: job_posts.php');
    exit;
}

try {
    $jobStmt = $pdo->prepare("
        SELECT
            jp.id,
            jp.title,
            jp.status,
            jp.partner_id,
            pp.company_name
        FROM job_posts jp
        LEFT JOIN partner_profiles pp ON jp.partner_id = pp.user_id
        WHERE jp.id = ?
          AND jp.partner_id = ?
        LIMIT 1
    ");
    $jobStmt->execute([$jobId, $partnerId]);
    $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        $_SESSION['flash_message'] = 'Job post not found or you do not have permission to invite for this job.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: job_posts.php');
        exit;
    }

    if (($job['status'] ?? '') !== 'Open') {
        $_SESSION['flash_message'] = 'This job post is not open, so invitations cannot be sent.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . $redirectUrl);
        exit;
    }

    $alumniStmt = $pdo->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.status,
            ap.course,
            ap.major
        FROM users u
        LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
        WHERE u.id = ?
          AND u.role = 'alumni'
        LIMIT 1
    ");
    $alumniStmt->execute([$alumniId]);
    $alumni = $alumniStmt->fetch(PDO::FETCH_ASSOC);

    if (!$alumni || ($alumni['status'] ?? '') !== 'active') {
        $_SESSION['flash_message'] = 'Selected alumni account is not available for invitation.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . $redirectUrl);
        exit;
    }

    $applicationStmt = $pdo->prepare("
        SELECT id
        FROM applications
        WHERE job_post_id = ?
          AND alumni_id = ?
        LIMIT 1
    ");
    $applicationStmt->execute([$jobId, $alumniId]);
    $existingApplicationId = $applicationStmt->fetchColumn();

    if ($existingApplicationId) {
        $_SESSION['flash_message'] = 'This alumni already applied to the job. Please review the application instead.';
        $_SESSION['flash_type'] = 'info';
        header('Location: applicants.php?job_id=' . $jobId);
        exit;
    }

    $recommendationStmt = $pdo->prepare("
        SELECT match_score
        FROM job_recommendations
        WHERE job_post_id = ?
          AND alumni_id = ?
        LIMIT 1
    ");
    $recommendationStmt->execute([$jobId, $alumniId]);
    $savedScore = $recommendationStmt->fetchColumn();

    if ($savedScore !== false && $savedScore !== null) {
        $matchScore = (int) $savedScore;
    }

    $companyName = trim((string) ($job['company_name'] ?? 'Partner Company'));
    if ($companyName === '') {
        $companyName = 'Partner Company';
    }

    $alumniName = trim(($alumni['first_name'] ?? '') . ' ' . ($alumni['last_name'] ?? ''));
    if ($alumniName === '') {
        $alumniName = 'Selected alumni';
    }

    $jobTitle = trim((string) ($job['title'] ?? 'Job Opportunity'));

    $inviteMessage = $companyName . ' invited you to apply for "' . $jobTitle . '". You were selected as a strong profile match by the company recruitment team.';

    if ($matchScore > 0) {
        $inviteMessage .= ' Match score: ' . $matchScore . '%.';
    }

    $pdo->beginTransaction();

    $inviteStmt = $pdo->prepare("
        INSERT INTO job_invitations (
            job_post_id,
            partner_id,
            alumni_id,
            match_score,
            message,
            status,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, 'Sent', NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            match_score = VALUES(match_score),
            message = VALUES(message),
            status = 'Sent',
            updated_at = NOW()
    ");
    $inviteStmt->execute([
        $jobId,
        $partnerId,
        $alumniId,
        $matchScore,
        $inviteMessage
    ]);

    $notifStmt = $pdo->prepare("
        INSERT INTO notifications (
            user_id,
            title,
            message,
            type,
            related_application_id,
            is_read,
            created_at
        ) VALUES (?, ?, ?, ?, NULL, 0, NOW())
    ");
    $notifStmt->execute([
        $alumniId,
        'Invitation to Apply',
        $inviteMessage,
        'job_invite'
    ]);

    $pdo->commit();

    $_SESSION['flash_message'] = 'Invitation sent to ' . $alumniName . '.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . $redirectUrl);
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['flash_message'] = 'Unable to send invitation. Make sure the job_invitations table exists, then try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirectUrl);
    exit;
}
