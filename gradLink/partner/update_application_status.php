<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

require_once '../includes/mailer.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

requireRole(['partner']);

$user_id = $_SESSION['user_id'];
$application_id = $_GET['id'] ?? null;

$allowedStatuses = [
    'Pending',
    'Under Review',
    'Shortlisted',
    'For Interview',
    'Interview Scheduled',
    'Offered',
    'Hired',
    'Rejected',
    'Withdrawn'
];

if (!$application_id || !ctype_digit((string) $application_id)) {
    header('Location: applicants.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        a.id,
        a.alumni_id,
        a.status,
        a.partner_notes,
        a.interview_datetime,
        a.rejection_reason,
        a.status_updated_at,
        a.status_updated_by,
        u.first_name,
        u.last_name,
        u.email AS alumni_email,
        ap.profile_photo AS alumni_photo,
        jp.title AS job_title,
        pp.company_name
    FROM applications a
    INNER JOIN job_posts jp ON a.job_post_id = jp.id
    INNER JOIN users u ON a.alumni_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = a.alumni_id
    LEFT JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    WHERE a.id = ? AND jp.partner_id = ?
    LIMIT 1
");
$stmt->execute([$application_id, $user_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    header('Location: applicants.php');
    exit;
}

$errors = [];

function formatDateTimeLocal($datetime)
{
    if (empty($datetime)) {
        return '';
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}

function formatDisplayDateTime($datetime)
{
    if (empty($datetime)) {
        return '—';
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return '—';
    }

    return date('F j, Y g:i A', $timestamp);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = trim($_POST['status'] ?? '');
    $partner_notes = trim($_POST['partner_notes'] ?? '');
    $interview_datetime_input = trim($_POST['interview_datetime'] ?? '');
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');

    $interview_datetime = null;

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Invalid application status.';
    }

    if ($status === 'Interview Scheduled') {
        if ($interview_datetime_input === '') {
            $errors[] = 'Interview date and time is required when status is Interview Scheduled.';
        } else {
            $date = DateTime::createFromFormat('Y-m-d\TH:i', $interview_datetime_input);

            if (!$date) {
                $errors[] = 'Invalid interview date and time.';
            } else {
                $interview_datetime = $date->format('Y-m-d H:i:s');
            }
        }
    } elseif ($interview_datetime_input !== '') {
        $date = DateTime::createFromFormat('Y-m-d\TH:i', $interview_datetime_input);

        if ($date) {
            $interview_datetime = $date->format('Y-m-d H:i:s');
        }
    }

    if ($status === 'Rejected' && $rejection_reason === '') {
        $errors[] = 'Reason for rejection is required when status is Rejected.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $previous_status = $application['status'] ?? 'Pending';

            $updateStmt = $pdo->prepare("
                UPDATE applications
                SET 
                    status = ?,
                    partner_notes = ?,
                    interview_datetime = ?,
                    rejection_reason = ?,
                    status_updated_at = NOW(),
                    status_updated_by = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $status,
                $partner_notes !== '' ? $partner_notes : null,
                $interview_datetime,
                $rejection_reason !== '' ? $rejection_reason : null,
                $user_id,
                $application_id
            ]);

            $logStmt = $pdo->prepare("
                INSERT INTO application_status_logs (
                    application_id,
                    previous_status,
                    new_status,
                    partner_notes,
                    interview_datetime,
                    rejection_reason,
                    updated_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $logStmt->execute([
                $application_id,
                $previous_status,
                $status,
                $partner_notes !== '' ? $partner_notes : null,
                $interview_datetime,
                $rejection_reason !== '' ? $rejection_reason : null,
                $user_id
            ]);

            sendApplicantNotification(
    $application['alumni_email'],
    $application['first_name'].' '.$application['last_name'],
    $application['job_title'],
    $application['company_name'] ?? 'Partner Company',
    $status,
    $partner_notes,
    $interview_datetime,
    $rejection_reason
);

            $companyName = $application['company_name'] ?: 'a partner company';

            $notificationMessage = 'Your application for "' . $application['job_title'] . '" at ' . $companyName . ' was updated from "' . $previous_status . '" to "' . $status . '".';

            if ($status === 'Interview Scheduled' && $interview_datetime) {
                $notificationMessage .= ' Interview schedule: ' . formatDisplayDateTime($interview_datetime) . '.';
            }

            if ($status === 'Rejected' && $rejection_reason !== '') {
                $notificationMessage .= ' Reason: ' . $rejection_reason;
            }

            if ($partner_notes !== '') {
                $notificationMessage .= ' Partner note: ' . $partner_notes;
            }

            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (
                    user_id,
                    title,
                    message,
                    type,
                    related_application_id,
                    is_read,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, 0, NOW())
            ");
            $notifStmt->execute([
                $application['alumni_id'],
                'Application Status Updated',
                $notificationMessage,
                'application_status',
                $application_id
            ]);

            $pdo->commit();

            $_SESSION['flash_message'] = 'Application status updated successfully.';
            $_SESSION['flash_type'] = 'success';

            header('Location: applicants.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Failed to update application status. Please check your database columns and try again.';
        }
    }
}

$historyStmt = $pdo->prepare("
    SELECT 
        asl.*,
        u.first_name,
        u.last_name
    FROM application_status_logs asl
    LEFT JOIN users u ON asl.updated_by = u.id
    WHERE asl.application_id = ?
    ORDER BY asl.updated_at DESC
");
$historyStmt->execute([$application_id]);
$statusHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Update Applicant Status';
$pageSubtitle = 'Manage the progress of a submitted job application.';
$activePage = 'applicants';

require_once '../includes/dashboard_header.php';
?>

<div class="panel-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="panel-title mb-0">Update Application Status</div>
        <a href="applicants.php" class="btn-light-outline">Back to Applicants</a>
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

    <div class="section-box mt-0">
        <div class="section-title">Application Details</div>

        <div class="info-list">
            <div class="info-item">
                <div class="info-key">Applicant</div>
                <div class="info-value d-flex align-items-center gap-2">
                    <?php $alumniPhotoUrl = profilePhotoUrl($application['alumni_photo'] ?? '', '../'); ?>
                    <?php if ($alumniPhotoUrl): ?>
                        <img src="<?= htmlspecialchars($alumniPhotoUrl) ?>" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
                    <?php endif; ?>
                    <span><?= htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) ?></span>
                </div>
            </div>

            <div class="info-item">
                <div class="info-key">Job Title</div>
                <div class="info-value">
                    <?= htmlspecialchars($application['job_title']) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-key">Company</div>
                <div class="info-value">
                    <?= htmlspecialchars($application['company_name'] ?? '—') ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-key">Current Status</div>
                <div class="info-value">
                    <?= htmlspecialchars($application['status']) ?>
                </div>
            </div>

            <?php if (!empty($application['status_updated_at'])): ?>
                <div class="info-item">
                    <div class="info-key">Last Updated</div>
                    <div class="info-value">
                        <?= htmlspecialchars(formatDisplayDateTime($application['status_updated_at'])) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="">
        <div class="section-box">
            <div class="section-title">Select New Status</div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Application Status</label>
                    <select name="status" id="statusSelect" class="form-select" required>
                        <?php foreach ($allowedStatuses as $option): ?>
                            <option value="<?= htmlspecialchars($option) ?>" <?= (($application['status'] ?? '') === $option) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6" id="interviewBox">
                    <label class="form-label">Interview Date and Time</label>
                    <input 
                        type="datetime-local" 
                        name="interview_datetime" 
                        class="form-control"
                        value="<?= htmlspecialchars(formatDateTimeLocal($application['interview_datetime'] ?? '')) ?>"
                    >
                    <div class="form-text">
                        Required only when status is Interview Scheduled.
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label">Partner Remarks / Notes</label>
                    <textarea 
                        name="partner_notes" 
                        class="form-control" 
                        rows="4"
                        placeholder="Add notes for this applicant..."
                    ><?= htmlspecialchars($application['partner_notes'] ?? '') ?></textarea>
                </div>

                <div class="col-12" id="rejectionBox">
                    <label class="form-label">Reason for Rejection</label>
                    <textarea 
                        name="rejection_reason" 
                        class="form-control" 
                        rows="4"
                        placeholder="Explain why the applicant was rejected..."
                    ><?= htmlspecialchars($application['rejection_reason'] ?? '') ?></textarea>
                    <div class="form-text">
                        Required only when status is Rejected.
                    </div>
                </div>
            </div>
        </div>

        <div class="d-grid mt-4">
            <button type="submit" class="btn btn-maroon btn-lg">Update Status</button>
        </div>
    </form>
</div>

<div class="panel-card mt-4">
    <div class="panel-title">Status Update History</div>

    <?php if (empty($statusHistory)): ?>
        <div class="text-muted">
            No status updates have been recorded yet.
        </div>
    <?php else: ?>
        <div class="quick-action-list">
            <?php foreach ($statusHistory as $log): ?>
                <div class="quick-action" style="display:block;">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <strong>
                                <?= htmlspecialchars($log['previous_status'] ?? '—') ?>
                                →
                                <?= htmlspecialchars($log['new_status'] ?? '—') ?>
                            </strong>

                            <div class="text-muted small mt-1">
                                Updated by:
                                <?= htmlspecialchars(trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: 'Unknown') ?>
                                •
                                <?= htmlspecialchars(formatDisplayDateTime($log['updated_at'] ?? '')) ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($log['interview_datetime'])): ?>
                        <div class="mt-3 text-muted small">
                            <strong>Interview Schedule:</strong>
                            <?= htmlspecialchars(formatDisplayDateTime($log['interview_datetime'])) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($log['partner_notes'])): ?>
                        <div class="mt-3 text-muted small">
                            <strong>Partner Notes:</strong><br>
                            <?= nl2br(htmlspecialchars($log['partner_notes'])) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($log['rejection_reason'])): ?>
                        <div class="mt-3 text-muted small">
                            <strong>Reason for Rejection:</strong><br>
                            <?= nl2br(htmlspecialchars($log['rejection_reason'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const statusSelect = document.getElementById('statusSelect');
    const interviewBox = document.getElementById('interviewBox');
    const rejectionBox = document.getElementById('rejectionBox');

    function toggleStatusFields() {
        const status = statusSelect.value;

        if (status === 'Interview Scheduled') {
            interviewBox.style.display = 'block';
        } else {
            interviewBox.style.display = 'none';
        }

        if (status === 'Rejected') {
            rejectionBox.style.display = 'block';
        } else {
            rejectionBox.style.display = 'none';
        }
    }

    statusSelect.addEventListener('change', toggleStatusFields);
    toggleStatusFields();
});
</script>

<?php require_once '../includes/dashboard_footer.php'; ?>