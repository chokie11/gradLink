<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$audienceFilter = $_GET['audience'] ?? 'all';
$eventFilter = $_GET['event'] ?? 'all';

$allowedStatuses = ['all', 'published', 'draft'];
$allowedAudiences = ['all', 'alumni', 'partner'];
$allowedEvents = ['all', 'upcoming', 'past', 'no_event'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

if (!in_array($audienceFilter, $allowedAudiences, true)) {
    $audienceFilter = 'all';
}

if (!in_array($eventFilter, $allowedEvents, true)) {
    $eventFilter = 'all';
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(a.title LIKE ? OR a.content LIKE ? OR a.location LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}

if ($statusFilter !== 'all') {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}

if ($audienceFilter !== 'all') {
    $where[] = 'a.audience = ?';
    $params[] = $audienceFilter;
}

if ($eventFilter === 'upcoming') {
    $where[] = 'a.event_date IS NOT NULL AND a.event_date >= CURDATE()';
} elseif ($eventFilter === 'past') {
    $where[] = 'a.event_date IS NOT NULL AND a.event_date < CURDATE()';
} elseif ($eventFilter === 'no_event') {
    $where[] = 'a.event_date IS NULL';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS creator_name
    FROM announcements a
    LEFT JOIN users u ON a.created_by = u.id
    $whereSql
    ORDER BY
        CASE
            WHEN a.status = 'published' THEN 0
            ELSE 1
        END,
        CASE
            WHEN a.event_date IS NULL THEN 1
            ELSE 0
        END,
        a.event_date ASC,
        a.created_at DESC
");
$stmt->execute($params);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_announcements,
        SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_count,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_count,
        SUM(CASE WHEN event_date IS NOT NULL AND event_date >= CURDATE() THEN 1 ELSE 0 END) AS upcoming_events,
        SUM(CASE WHEN event_date IS NOT NULL THEN 1 ELSE 0 END) AS event_count
    FROM announcements
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$latestPublishedStmt = $pdo->query("
    SELECT title, created_at
    FROM announcements
    WHERE status = 'published'
    ORDER BY created_at DESC
    LIMIT 1
");
$latestPublished = $latestPublishedStmt->fetch(PDO::FETCH_ASSOC);

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function announcementStatusMeta(string $status): array {
    return match ($status) {
        'published' => ['Published', 'status-published'],
        'draft' => ['Draft', 'status-draft'],
        default => [ucfirst($status ?: 'Unknown'), 'status-muted']
    };
}

function audienceMeta(string $audience): array {
    return match ($audience) {
        'all' => ['All Users', 'audience-all'],
        'alumni' => ['Alumni', 'audience-alumni'],
        'partner' => ['Partners', 'audience-partner'],
        default => [ucfirst($audience ?: 'Audience'), 'audience-muted']
    };
}

function announcementExcerpt(string $content, int $limit = 190): string {
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? '');
    if (mb_strlen($plain) <= $limit) {
        return $plain;
    }
    return mb_substr($plain, 0, $limit - 3) . '...';
}

function eventState(?string $date): array {
    if (empty($date)) {
        return ['No event date', 'event-neutral'];
    }

    $today = new DateTime('today');
    $eventDate = new DateTime($date);
    $diff = (int) $today->diff($eventDate)->format('%r%a');

    if ($diff === 0) {
        return ['Today', 'event-today'];
    }

    if ($diff > 0) {
        return [$diff === 1 ? 'Tomorrow' : $diff . ' days left', 'event-upcoming'];
    }

    return [abs($diff) . ' days ago', 'event-past'];
}

$queryBase = $_GET;

$pageTitle = 'Announcements';
$pageSubtitle = 'Manage platform announcements, events, and audience-targeted notices.';
$activePage = 'announcements';

require_once '../includes/dashboard_header.php';
?>

<style>
    .admin-ann-shell {
        --admin-maroon: #7a1118;
        --admin-maroon-dark: #4f080d;
        --admin-maroon-soft: #fff2f3;
        --admin-ink: #241516;
        --admin-muted: #756164;
        --admin-line: #f0dddd;
        --admin-bg: #fffafa;
    }

    .admin-ann-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 28px;
        margin-bottom: 22px;
        color: #fff;
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, .22), transparent 32%),
            linear-gradient(135deg, #3b070b 0%, #7a1118 48%, #a02830 100%);
        box-shadow: 0 22px 55px rgba(122, 17, 24, .22);
    }

    .admin-ann-hero::after {
        content: "";
        position: absolute;
        width: 360px;
        height: 360px;
        border: 1px solid rgba(255, 255, 255, .16);
        border-radius: 50%;
        right: -140px;
        bottom: -170px;
    }

    .admin-ann-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(280px, .65fr);
        gap: 24px;
        align-items: stretch;
    }

    .admin-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, .14);
        border: 1px solid rgba(255, 255, 255, .18);
        color: rgba(255, 255, 255, .9);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .7px;
        text-transform: uppercase;
    }

    .admin-ann-title {
        margin: 16px 0 10px;
        font-size: clamp(30px, 4vw, 48px);
        line-height: 1.02;
        font-weight: 900;
        letter-spacing: -1.4px;
    }

    .admin-ann-text {
        max-width: 760px;
        color: rgba(255, 255, 255, .82);
        font-size: 15px;
        line-height: 1.7;
    }

    .admin-hero-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 22px;
    }

    .admin-btn-primary,
    .admin-btn-light,
    .admin-btn-ghost,
    .admin-btn-danger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 42px;
        padding: 10px 15px;
        border-radius: 14px;
        font-size: 13px;
        font-weight: 850;
        text-decoration: none;
        border: 1px solid transparent;
        transition: all .18s ease;
        white-space: nowrap;
    }

    .admin-btn-primary {
        background: linear-gradient(135deg, var(--admin-maroon), var(--admin-maroon-dark));
        color: #fff;
        box-shadow: 0 12px 24px rgba(122, 17, 24, .22);
    }

    .admin-btn-primary:hover {
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 16px 28px rgba(122, 17, 24, .28);
    }

    .admin-btn-light {
        background: rgba(255, 255, 255, .96);
        color: var(--admin-maroon);
        border-color: rgba(255, 255, 255, .35);
    }

    .admin-btn-light:hover {
        color: var(--admin-maroon-dark);
        transform: translateY(-1px);
        background: #fff;
    }

    .admin-btn-ghost {
        color: var(--admin-maroon);
        background: #fff;
        border-color: var(--admin-line);
    }

    .admin-btn-ghost:hover {
        color: var(--admin-maroon-dark);
        background: var(--admin-maroon-soft);
        border-color: #efc8cc;
    }

    .admin-btn-danger {
        color: #991b1b;
        background: #fff7f7;
        border-color: #ffd7d7;
    }

    .admin-btn-danger:hover {
        color: #7f1d1d;
        background: #fee2e2;
    }

    .admin-hero-panel {
        border-radius: 24px;
        padding: 20px;
        background: rgba(255, 255, 255, .13);
        border: 1px solid rgba(255, 255, 255, .18);
        backdrop-filter: blur(12px);
    }

    .hero-panel-label {
        font-size: 12px;
        font-weight: 900;
        color: rgba(255, 255, 255, .72);
        text-transform: uppercase;
        letter-spacing: .8px;
    }

    .hero-panel-title {
        margin-top: 8px;
        color: #fff;
        font-size: 20px;
        font-weight: 900;
        line-height: 1.25;
    }

    .hero-panel-meta {
        margin-top: 8px;
        color: rgba(255, 255, 255, .77);
        font-size: 13px;
        line-height: 1.55;
    }

    .admin-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .admin-stat-card {
        border-radius: 22px;
        padding: 18px;
        background: #fff;
        border: 1px solid var(--admin-line);
        box-shadow: 0 12px 28px rgba(122, 17, 24, .06);
    }

    .admin-stat-label {
        color: var(--admin-muted);
        font-size: 12px;
        font-weight: 850;
        text-transform: uppercase;
        letter-spacing: .7px;
    }

    .admin-stat-value {
        margin-top: 8px;
        color: var(--admin-ink);
        font-size: 30px;
        line-height: 1;
        font-weight: 950;
        letter-spacing: -1px;
    }

    .admin-stat-note {
        margin-top: 8px;
        color: var(--admin-muted);
        font-size: 13px;
    }

    .admin-filter-card,
    .admin-list-card {
        border-radius: 26px;
        background: #fff;
        border: 1px solid var(--admin-line);
        box-shadow: 0 14px 34px rgba(122, 17, 24, .07);
        margin-bottom: 22px;
    }

    .admin-filter-card {
        padding: 18px;
    }

    .admin-filter-grid {
        display: grid;
        grid-template-columns: minmax(240px, 1.2fr) repeat(3, minmax(150px, .75fr)) auto;
        gap: 12px;
        align-items: end;
    }

    .admin-field label {
        color: var(--admin-muted);
        font-size: 12px;
        font-weight: 850;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: .6px;
    }

    .admin-control {
        min-height: 44px;
        border-radius: 14px !important;
        border: 1px solid #ead6d8 !important;
        background: #fffdfd !important;
        color: var(--admin-ink) !important;
        font-size: 14px;
    }

    .admin-control:focus {
        border-color: #c04b55 !important;
        box-shadow: 0 0 0 .18rem rgba(122, 17, 24, .12) !important;
    }

    .admin-list-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        padding: 20px 22px;
        border-bottom: 1px solid var(--admin-line);
    }

    .admin-list-title {
        color: var(--admin-ink);
        font-size: 18px;
        font-weight: 950;
        letter-spacing: -.35px;
    }

    .admin-list-subtitle {
        color: var(--admin-muted);
        font-size: 13px;
        margin-top: 3px;
    }

    .announcement-stack {
        padding: 16px;
        display: grid;
        gap: 14px;
    }

    .announcement-card {
        position: relative;
        overflow: hidden;
        border-radius: 22px;
        background: linear-gradient(180deg, #ffffff 0%, #fffafa 100%);
        border: 1px solid var(--admin-line);
        padding: 18px;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }

    .announcement-card:hover {
        transform: translateY(-2px);
        border-color: #e4b7bc;
        box-shadow: 0 18px 36px rgba(122, 17, 24, .09);
    }

    .announcement-card::before {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 5px;
        background: linear-gradient(180deg, var(--admin-maroon), #d57b83);
    }

    .announcement-main-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 16px;
    }

    .announcement-title {
        color: var(--admin-ink);
        font-size: 18px;
        line-height: 1.25;
        font-weight: 950;
        letter-spacing: -.35px;
    }

    .announcement-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        color: var(--admin-muted);
        font-size: 12.5px;
        margin-top: 8px;
    }

    .announcement-content {
        color: #59484b;
        font-size: 14px;
        line-height: 1.7;
        margin-top: 14px;
        max-width: 980px;
    }

    .badge-admin {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 850;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .status-published {
        color: #11633b;
        background: #e9f9f0;
        border-color: #c9f1db;
    }

    .status-draft {
        color: #8a4b06;
        background: #fff8e5;
        border-color: #fde8a7;
    }

    .status-muted,
    .audience-muted {
        color: #64748b;
        background: #f8fafc;
        border-color: #e2e8f0;
    }

    .audience-all {
        color: #111827;
        background: #f3f4f6;
        border-color: #e5e7eb;
    }

    .audience-alumni {
        color: #7a1118;
        background: #fff2f3;
        border-color: #f0c6ca;
    }

    .audience-partner {
        color: #8a4b06;
        background: #fff8e5;
        border-color: #fde8a7;
    }

    .event-strip {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
        margin-top: 13px;
    }

    .event-chip {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 8px 10px;
        border-radius: 13px;
        font-size: 12.5px;
        font-weight: 800;
        border: 1px solid #ead6d8;
        background: #fff;
        color: #5e4a4d;
    }

    .event-today {
        color: #991b1b;
        background: #fff1f2;
        border-color: #fecdd3;
    }

    .event-upcoming {
        color: #166534;
        background: #f0fdf4;
        border-color: #bbf7d0;
    }

    .event-past {
        color: #64748b;
        background: #f8fafc;
        border-color: #e2e8f0;
    }

    .event-neutral {
        color: #6b7280;
        background: #fafafa;
        border-color: #e5e7eb;
    }

    .announcement-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: flex-start;
    }

    .empty-admin-state {
        padding: 46px 20px;
        text-align: center;
        color: var(--admin-muted);
    }

    .empty-admin-icon {
        width: 76px;
        height: 76px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 24px;
        background: var(--admin-maroon-soft);
        color: var(--admin-maroon);
        font-size: 30px;
        margin-bottom: 14px;
    }

    .empty-admin-title {
        color: var(--admin-ink);
        font-size: 18px;
        font-weight: 950;
        margin-bottom: 6px;
    }

    @media (max-width: 1100px) {
        .admin-ann-hero-grid,
        .admin-filter-grid {
            grid-template-columns: 1fr;
        }

        .admin-stat-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 720px) {
        .admin-ann-hero {
            padding: 22px;
            border-radius: 22px;
        }

        .admin-stat-grid {
            grid-template-columns: 1fr;
        }

        .announcement-main-grid {
            grid-template-columns: 1fr;
        }

        .announcement-actions {
            justify-content: flex-start;
        }

        .admin-hero-actions,
        .admin-list-head {
            align-items: stretch;
            flex-direction: column;
        }

        .admin-btn-primary,
        .admin-btn-light,
        .admin-btn-ghost,
        .admin-btn-danger {
            width: 100%;
        }
    }
</style>

<div class="admin-ann-shell">
    <section class="admin-ann-hero">
        <div class="admin-ann-hero-grid">
            <div>
                <span class="admin-eyebrow">Admin Broadcast Console</span>
                <h1 class="admin-ann-title">Announcement Management</h1>
                <div class="admin-ann-text">
                    Create, organize, and review official announcements for alumni, partners, and the whole platform.
                    Use this page to keep published notices, events, and drafts easy to monitor.
                </div>

                <div class="admin-hero-actions">
                    <a href="create_announcement.php" class="admin-btn-light">+ Create Announcement</a>
                    <a href="announcements.php?status=published" class="admin-btn-light">View Published</a>
                    <a href="announcements.php?event=upcoming" class="admin-btn-light">Upcoming Events</a>
                </div>
            </div>

            <div class="admin-hero-panel">
                <div class="hero-panel-label">Latest Published Notice</div>
                <?php if ($latestPublished): ?>
                    <div class="hero-panel-title"><?= e($latestPublished['title']) ?></div>
                    <div class="hero-panel-meta">
                        Published <?= date('F j, Y', strtotime($latestPublished['created_at'])) ?>.
                        Keep announcements timely and audience-specific for better engagement.
                    </div>
                <?php else: ?>
                    <div class="hero-panel-title">No published announcement yet</div>
                    <div class="hero-panel-meta">Create your first announcement or publish a saved draft.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="admin-stat-grid">
        <div class="admin-stat-card">
            <div class="admin-stat-label">Total Records</div>
            <div class="admin-stat-value"><?= (int) ($stats['total_announcements'] ?? 0) ?></div>
            <div class="admin-stat-note">Announcements and events</div>
        </div>

        <div class="admin-stat-card">
            <div class="admin-stat-label">Published</div>
            <div class="admin-stat-value"><?= (int) ($stats['published_count'] ?? 0) ?></div>
            <div class="admin-stat-note">Visible to selected audiences</div>
        </div>

        <div class="admin-stat-card">
            <div class="admin-stat-label">Drafts</div>
            <div class="admin-stat-value"><?= (int) ($stats['draft_count'] ?? 0) ?></div>
            <div class="admin-stat-note">Needs review or publishing</div>
        </div>

        <div class="admin-stat-card">
            <div class="admin-stat-label">Upcoming Events</div>
            <div class="admin-stat-value"><?= (int) ($stats['upcoming_events'] ?? 0) ?></div>
            <div class="admin-stat-note">Scheduled event notices</div>
        </div>
    </section>

    <form method="GET" class="admin-filter-card">
        <div class="admin-filter-grid">
            <div class="admin-field">
                <label for="search">Search</label>
                <input type="text"
                       name="search"
                       id="search"
                       class="form-control admin-control"
                       value="<?= e($search) ?>"
                       placeholder="Title, content, location, or creator">
            </div>

            <div class="admin-field">
                <label for="status">Status</label>
                <select name="status" id="status" class="form-select admin-control">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>>Published</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                </select>
            </div>

            <div class="admin-field">
                <label for="audience">Audience</label>
                <select name="audience" id="audience" class="form-select admin-control">
                    <option value="all" <?= $audienceFilter === 'all' ? 'selected' : '' ?>>All Audiences</option>
                    <option value="alumni" <?= $audienceFilter === 'alumni' ? 'selected' : '' ?>>Alumni</option>
                    <option value="partner" <?= $audienceFilter === 'partner' ? 'selected' : '' ?>>Partners</option>
                </select>
            </div>

            <div class="admin-field">
                <label for="event">Event</label>
                <select name="event" id="event" class="form-select admin-control">
                    <option value="all" <?= $eventFilter === 'all' ? 'selected' : '' ?>>All Posts</option>
                    <option value="upcoming" <?= $eventFilter === 'upcoming' ? 'selected' : '' ?>>Upcoming Events</option>
                    <option value="past" <?= $eventFilter === 'past' ? 'selected' : '' ?>>Past Events</option>
                    <option value="no_event" <?= $eventFilter === 'no_event' ? 'selected' : '' ?>>No Event Date</option>
                </select>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="admin-btn-primary">Apply</button>
                <a href="announcements.php" class="admin-btn-ghost">Reset</a>
            </div>
        </div>
    </form>

    <section class="admin-list-card">
        <div class="admin-list-head">
            <div>
                <div class="admin-list-title">Announcement Records</div>
                <div class="admin-list-subtitle">
                    Showing <?= count($announcements) ?> result<?= count($announcements) === 1 ? '' : 's' ?> based on your current view.
                </div>
            </div>

            <a href="create_announcement.php" class="admin-btn-primary">+ New Announcement</a>
        </div>

        <?php if (empty($announcements)): ?>
            <div class="empty-admin-state">
                <div class="empty-admin-icon">!</div>
                <div class="empty-admin-title">No announcements found</div>
                <div>Try changing your filters, or create a new announcement for the platform.</div>
                <div class="mt-3">
                    <a href="create_announcement.php" class="admin-btn-primary">Create Announcement</a>
                </div>
            </div>
        <?php else: ?>
            <div class="announcement-stack">
                <?php foreach ($announcements as $item): ?>
                    <?php
                        [$statusLabel, $statusClass] = announcementStatusMeta((string) ($item['status'] ?? ''));
                        [$audienceLabel, $audienceClass] = audienceMeta((string) ($item['audience'] ?? ''));
                        [$eventLabel, $eventClass] = eventState($item['event_date'] ?? null);
                    ?>
                    <article class="announcement-card">
                        <div class="announcement-main-grid">
                            <div>
                                <div class="d-flex gap-2 flex-wrap mb-2">
                                    <span class="badge-admin <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
                                    <span class="badge-admin <?= e($audienceClass) ?>"><?= e($audienceLabel) ?></span>
                                    <span class="badge-admin <?= e($eventClass) ?>"><?= e($eventLabel) ?></span>
                                </div>

                                <div class="announcement-title"><?= e($item['title']) ?></div>
                                <div class="announcement-meta">
                                    <span>Posted <?= date('F j, Y', strtotime($item['created_at'])) ?></span>
                                    <?php if (!empty($item['creator_name'])): ?>
                                        <span>By <?= e($item['creator_name']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($item['event_date']) || !empty($item['location'])): ?>
                                    <div class="event-strip">
                                        <?php if (!empty($item['event_date'])): ?>
                                            <span class="event-chip">Event Date: <?= date('F j, Y', strtotime($item['event_date'])) ?></span>
                                        <?php endif; ?>

                                        <?php if (!empty($item['location'])): ?>
                                            <span class="event-chip">Location: <?= e($item['location']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="announcement-content">
                                    <?= e(announcementExcerpt((string) ($item['content'] ?? ''))) ?>
                                </div>
                            </div>

                            <div class="announcement-actions">
                                <a href="edit_announcement.php?id=<?= (int) $item['id'] ?>" class="admin-btn-ghost">Edit</a>
                                <a href="delete_announcement.php?id=<?= (int) $item['id'] ?>"
                                   class="admin-btn-danger"
                                   onclick="return confirm('Are you sure you want to delete this announcement?');">
                                    Delete
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
