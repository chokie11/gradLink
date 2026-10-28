<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $event_date = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $audience = trim($_POST['audience'] ?? 'alumni');
    $status = trim($_POST['status'] ?? 'published');
    $created_by = $_SESSION['user_id'];

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
        $stmt = $pdo->prepare("
            INSERT INTO announcements (title, content, event_date, location, audience, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $title,
            $content,
            $event_date !== '' ? $event_date : null,
            $location !== '' ? $location : null,
            $audience,
            $status,
            $created_by
        ]);

        $success = 'Announcement created successfully.';
    }
}

$pageTitle = 'Create Announcement';
$pageSubtitle = 'Post announcements and events for alumni or partners.';
$activePage = 'announcements';

require_once '../includes/dashboard_header.php';
?>

<div class="panel-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="panel-title mb-0">New Announcement / Event</div>
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
                    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Event Date</label>
                    <input type="date" name="event_date" class="form-control" value="<?= htmlspecialchars($_POST['event_date'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Location</label>
                    <input type="text" name="location" class="form-control" value="<?= htmlspecialchars($_POST['location'] ?? '') ?>" placeholder="Optional location">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Audience</label>
                    <select name="audience" class="form-select">
                        <option value="alumni" <?= (($_POST['audience'] ?? 'alumni') === 'alumni') ? 'selected' : '' ?>>Alumni</option>
                        <option value="partner" <?= (($_POST['audience'] ?? '') === 'partner') ? 'selected' : '' ?>>Partner</option>
                        <option value="all" <?= (($_POST['audience'] ?? '') === 'all') ? 'selected' : '' ?>>All</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="published" <?= (($_POST['status'] ?? 'published') === 'published') ? 'selected' : '' ?>>Published</option>
                        <option value="draft" <?= (($_POST['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Content</label>
                    <textarea name="content" class="form-control" rows="6" required><?= htmlspecialchars($_POST['content'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="d-grid mt-4">
            <button type="submit" class="btn btn-maroon btn-lg">Publish Announcement</button>
        </div>
    </form>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>