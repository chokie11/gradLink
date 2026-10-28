<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$id = $_GET['id'] ?? null;

if (!$id || !ctype_digit((string)$id)) {
    header('Location: announcements.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM announcements
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$announcement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$announcement) {
    header('Location: announcements.php');
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $event_date = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $audience = trim($_POST['audience'] ?? 'alumni');
    $status = trim($_POST['status'] ?? 'published');

    if ($title === '' || $content === '') {
        $errors[] = 'Title and content are required.';
    }

    if (!in_array($audience, ['all', 'alumni', 'partner'])) {
        $errors[] = 'Invalid audience selected.';
    }

    if (!in_array($status, ['published', 'draft'])) {
        $errors[] = 'Invalid status selected.';
    }

    if (empty($errors)) {
        $updateStmt = $pdo->prepare("
            UPDATE announcements
            SET title = ?, content = ?, event_date = ?, location = ?, audience = ?, status = ?
            WHERE id = ?
        ");

        $updateStmt->execute([
            $title,
            $content,
            $event_date !== '' ? $event_date : null,
            $location !== '' ? $location : null,
            $audience,
            $status,
            $id
        ]);

        $success = 'Announcement updated successfully.';

        $stmt = $pdo->prepare("
            SELECT *
            FROM announcements
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$pageTitle = 'Edit Announcement';
$pageSubtitle = 'Update announcement details and publishing settings.';
$activePage = 'announcements';

require_once '../includes/dashboard_header.php';
?>

<div class="panel-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="panel-title mb-0">Edit Announcement / Event</div>
        <a href="announcements.php" class="btn-light-outline">Back to List</a>
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

    <?php if ($success): ?>
        <div class="alert alert-success rounded-4">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="section-box mt-0">
            <div class="section-title">Announcement Details</div>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Title</label>
                    <input
                        type="text"
                        name="title"
                        class="form-control"
                        value="<?= htmlspecialchars($announcement['title'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label">Event Date</label>
                    <input
                        type="date"
                        name="event_date"
                        class="form-control"
                        value="<?= htmlspecialchars($announcement['event_date'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label">Location</label>
                    <input
                        type="text"
                        name="location"
                        class="form-control"
                        value="<?= htmlspecialchars($announcement['location'] ?? '') ?>"
                        placeholder="Optional location"
                    >
                </div>

                <div class="col-md-2">
                    <label class="form-label">Audience</label>
                    <select name="audience" class="form-select">
                        <option value="alumni" <?= (($announcement['audience'] ?? '') === 'alumni') ? 'selected' : '' ?>>Alumni</option>
                        <option value="partner" <?= (($announcement['audience'] ?? '') === 'partner') ? 'selected' : '' ?>>Partner</option>
                        <option value="all" <?= (($announcement['audience'] ?? '') === 'all') ? 'selected' : '' ?>>All</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="published" <?= (($announcement['status'] ?? '') === 'published') ? 'selected' : '' ?>>Published</option>
                        <option value="draft" <?= (($announcement['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Content</label>
                    <textarea name="content" class="form-control" rows="6" required><?= htmlspecialchars($announcement['content'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="d-grid mt-4">
            <button type="submit" class="btn btn-maroon btn-lg">Save Changes</button>
        </div>
    </form>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>