<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$application_id = $_GET['id'] ?? null;

if (!$application_id || !ctype_digit((string)$application_id)) {
    header('Location: applications.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        a.id,
        a.status,
        a.applicant_name,
        jp.title AS job_title,
        pp.company_name
    FROM applications a
    INNER JOIN job_posts jp ON a.job_post_id = jp.id
    LEFT JOIN partner_profiles pp ON jp.partner_id = pp.user_id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->execute([$application_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    header('Location: applications.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = trim($_POST['status'] ?? '');

    if (!in_array($status, ['Pending', 'Reviewed', 'Hired', 'Not Selected'])) {
        $errors[] = 'Invalid application status.';
    }

    if (empty($errors)) {
        $updateStmt = $pdo->prepare("
            UPDATE applications
            SET status = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$status, $application_id]);

        header('Location: applications.php');
        exit;
    }
}

$pageTitle = 'Update Application Status';
$pageSubtitle = 'Admin review and update of application status.';
$activePage = 'applications';

require_once '../includes/dashboard_header.php';
?>

<div class="panel-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="panel-title mb-0">Update Application Status</div>
        <a href="applications.php" class="btn-light-outline">Back to Applications</a>
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
                <div class="info-value"><?= htmlspecialchars($application['applicant_name'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="info-key">Job Title</div>
                <div class="info-value"><?= htmlspecialchars($application['job_title'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="info-key">Company</div>
                <div class="info-value"><?= htmlspecialchars($application['company_name'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="info-key">Current Status</div>
                <div class="info-value"><?= htmlspecialchars($application['status'] ?? '—') ?></div>
            </div>
        </div>
    </div>

    <form method="POST" action="">
        <div class="section-box">
            <div class="section-title">Select New Status</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Application Status</label>
                    <select name="status" class="form-select">
                        <option value="Pending" <?= (($application['status'] ?? '') === 'Pending') ? 'selected' : '' ?>>Pending</option>
                        <option value="Reviewed" <?= (($application['status'] ?? '') === 'Reviewed') ? 'selected' : '' ?>>Reviewed</option>
                        <option value="Hired" <?= (($application['status'] ?? '') === 'Hired') ? 'selected' : '' ?>>Hired</option>
                        <option value="Not Selected" <?= (($application['status'] ?? '') === 'Not Selected') ? 'selected' : '' ?>>Not Selected</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="d-grid mt-4">
            <button type="submit" class="btn btn-maroon btn-lg">Update Status</button>
        </div>
    </form>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>