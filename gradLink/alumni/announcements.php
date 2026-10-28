<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$stmt = $pdo->prepare("
    SELECT *
    FROM announcements
    WHERE status = 'published'
      AND audience IN ('all', 'alumni')
    ORDER BY 
        CASE WHEN event_date IS NULL THEN 1 ELSE 0 END,
        event_date ASC,
        created_at DESC
");
$stmt->execute();
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$upcomingEvents = [];
$latestAnnouncements = [];

foreach ($announcements as $item) {
    if (!empty($item['event_date']) && $item['event_date'] >= $today) {
        $upcomingEvents[] = $item;
    } else {
        $latestAnnouncements[] = $item;
    }
}

$latestPostDate = null;
foreach ($announcements as $item) {
    if (!empty($item['created_at']) && ($latestPostDate === null || strtotime($item['created_at']) > strtotime($latestPostDate))) {
        $latestPostDate = $item['created_at'];
    }
}

$pageTitle = 'Announcements';
$pageSubtitle = 'Stay updated with official alumni announcements and upcoming events.';
$activePage = 'announcements';

require_once '../includes/dashboard_header.php';
?>

<style>
    .ann-page {
        --ann-maroon: var(--maroon, #800000);
        --ann-dark-maroon: var(--dark-maroon, #5b0000);
        --ann-soft-maroon: #fff5f5;
        --ann-border: #f0dddd;
        --ann-text: #2b2020;
        --ann-muted: #7b6b6b;
    }

    .ann-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 34px;
        margin-bottom: 24px;
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.28), transparent 30%),
            linear-gradient(135deg, var(--ann-maroon), var(--ann-dark-maroon));
        color: #ffffff;
        box-shadow: 0 18px 45px rgba(128, 0, 0, 0.18);
    }

    .ann-hero::after {
        content: "";
        position: absolute;
        width: 260px;
        height: 260px;
        right: -80px;
        bottom: -120px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.12);
    }

    .ann-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.55fr);
        gap: 24px;
        align-items: stretch;
    }

    .ann-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.2);
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.2px;
    }

    .ann-title {
        margin-top: 18px;
        margin-bottom: 12px;
        font-size: clamp(30px, 4vw, 46px);
        line-height: 1.05;
        font-weight: 900;
        letter-spacing: -1.2px;
    }

    .ann-subtitle {
        max-width: 760px;
        margin: 0;
        color: rgba(255, 255, 255, 0.84);
        font-size: 16px;
        line-height: 1.7;
    }

    .ann-hero-stat {
        height: 100%;
        padding: 22px;
        border-radius: 22px;
        background: rgba(255, 255, 255, 0.13);
        border: 1px solid rgba(255, 255, 255, 0.22);
        backdrop-filter: blur(10px);
    }

    .ann-hero-stat .label {
        color: rgba(255, 255, 255, 0.74);
        font-size: 13px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.7px;
    }

    .ann-hero-stat .value {
        margin-top: 8px;
        font-size: 44px;
        font-weight: 900;
        line-height: 1;
    }

    .ann-hero-stat .note {
        margin-top: 12px;
        color: rgba(255, 255, 255, 0.82);
        line-height: 1.55;
    }

    .ann-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .ann-stat-card {
        background: #ffffff;
        border: 1px solid var(--ann-border);
        border-radius: 22px;
        padding: 20px;
        box-shadow: 0 14px 34px rgba(128, 0, 0, 0.06);
    }

    .ann-stat-label {
        color: var(--ann-muted);
        font-size: 13px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.6px;
    }

    .ann-stat-value {
        margin-top: 8px;
        color: var(--ann-text);
        font-size: 28px;
        font-weight: 900;
        line-height: 1.1;
    }

    .ann-stat-note {
        margin-top: 6px;
        color: var(--ann-muted);
        font-size: 13px;
    }

    .ann-section {
        background: #ffffff;
        border: 1px solid var(--ann-border);
        border-radius: 26px;
        padding: 24px;
        box-shadow: 0 16px 38px rgba(128, 0, 0, 0.06);
        margin-bottom: 24px;
    }

    .ann-section-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }

    .ann-section-title {
        color: var(--ann-text);
        font-size: 20px;
        font-weight: 900;
        letter-spacing: -0.35px;
        margin: 0;
    }

    .ann-section-desc {
        color: var(--ann-muted);
        margin-top: 5px;
        margin-bottom: 0;
        line-height: 1.55;
    }

    .ann-count-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 44px;
        padding: 8px 12px;
        border-radius: 999px;
        background: var(--ann-soft-maroon);
        color: var(--ann-maroon);
        border: 1px solid var(--ann-border);
        font-weight: 900;
        white-space: nowrap;
    }

    .event-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 16px;
    }

    .event-card {
        position: relative;
        display: flex;
        gap: 14px;
        padding: 18px;
        border-radius: 22px;
        background: linear-gradient(180deg, #ffffff, #fffafa);
        border: 1px solid var(--ann-border);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .event-card:hover,
    .announcement-card:hover {
        transform: translateY(-2px);
        border-color: #e6bebe;
        box-shadow: 0 18px 35px rgba(128, 0, 0, 0.09);
    }

    .date-badge {
        width: 62px;
        min-width: 62px;
        height: 68px;
        border-radius: 18px;
        overflow: hidden;
        border: 1px solid #ead0d0;
        background: #ffffff;
        text-align: center;
        box-shadow: 0 10px 22px rgba(128, 0, 0, 0.08);
    }

    .date-badge .month {
        display: block;
        padding: 6px 0 5px;
        background: var(--ann-maroon);
        color: #ffffff;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.7px;
    }

    .date-badge .day {
        display: block;
        padding-top: 7px;
        color: var(--ann-text);
        font-size: 24px;
        font-weight: 900;
        line-height: 1;
    }

    .ann-card-title {
        color: var(--ann-text);
        font-size: 16px;
        font-weight: 900;
        line-height: 1.35;
        margin-bottom: 5px;
    }

    .ann-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin-top: 8px;
    }

    .ann-meta-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #faf3f3;
        border: 1px solid #f0dddd;
        color: #6f5555;
        font-size: 12px;
        font-weight: 700;
    }

    .ann-content {
        color: #5f5252;
        line-height: 1.7;
        margin-top: 12px;
        font-size: 14.5px;
    }

    .announcement-list {
        display: grid;
        gap: 14px;
    }

    .announcement-card {
        padding: 20px;
        border-radius: 22px;
        background: #ffffff;
        border: 1px solid var(--ann-border);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .announcement-top {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        align-items: flex-start;
    }

    .type-badge {
        display: inline-flex;
        align-items: center;
        padding: 7px 11px;
        border-radius: 999px;
        background: var(--ann-soft-maroon);
        color: var(--ann-maroon);
        border: 1px solid var(--ann-border);
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
    }

    .empty-announcements {
        padding: 44px 22px;
        text-align: center;
        border-radius: 22px;
        background: #fffafa;
        border: 1px dashed #e8caca;
        color: var(--ann-muted);
    }

    .empty-icon {
        width: 58px;
        height: 58px;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--ann-soft-maroon);
        color: var(--ann-maroon);
        font-size: 24px;
        margin-bottom: 12px;
    }

    .empty-title {
        color: var(--ann-text);
        font-size: 18px;
        font-weight: 900;
        margin-bottom: 6px;
    }

    @media (max-width: 900px) {
        .ann-hero-grid,
        .ann-stats {
            grid-template-columns: 1fr;
        }

        .ann-hero {
            padding: 26px;
            border-radius: 24px;
        }
    }

    @media (max-width: 640px) {
        .ann-section {
            padding: 18px;
            border-radius: 22px;
        }

        .ann-section-head,
        .announcement-top {
            flex-direction: column;
        }

        .event-card {
            flex-direction: column;
        }

        .date-badge {
            width: 74px;
        }
    }
</style>

<div class="ann-page">
    <section class="ann-hero fade-in">
        <div class="ann-hero-grid">
            <div>
                <span class="ann-eyebrow">Official Alumni Updates</span>
                <h1 class="ann-title">Announcements and Events</h1>
                <p class="ann-subtitle">
                    Stay informed with official school updates, alumni activities, reminders, events, and career-related announcements prepared for alumni members.
                </p>
            </div>

            <div class="ann-hero-stat">
                <div class="label">Published Updates</div>
                <div class="value"><?= count($announcements) ?></div>
                <div class="note">
                    <?= count($announcements) === 1 ? 'Official update is available for your account.' : 'Official updates are available for your account.' ?>
                </div>
            </div>
        </div>
    </section>

    <div class="ann-stats fade-in">
        <div class="ann-stat-card">
            <div class="ann-stat-label">Total Announcements</div>
            <div class="ann-stat-value"><?= count($announcements) ?></div>
            <div class="ann-stat-note">Published for alumni</div>
        </div>

        <div class="ann-stat-card">
            <div class="ann-stat-label">Upcoming Events</div>
            <div class="ann-stat-value"><?= count($upcomingEvents) ?></div>
            <div class="ann-stat-note">Events scheduled ahead</div>
        </div>

        <div class="ann-stat-card">
            <div class="ann-stat-label">Latest Post</div>
            <div class="ann-stat-value" style="font-size:22px;">
                <?= $latestPostDate ? date('M j, Y', strtotime($latestPostDate)) : 'None' ?>
            </div>
            <div class="ann-stat-note">Most recent admin update</div>
        </div>
    </div>

    <?php if (!empty($upcomingEvents)): ?>
        <section class="ann-section fade-in">
            <div class="ann-section-head">
                <div>
                    <h2 class="ann-section-title">Upcoming Events</h2>
                    <p class="ann-section-desc">Events and activities with scheduled dates.</p>
                </div>
                <span class="ann-count-pill"><?= count($upcomingEvents) ?></span>
            </div>

            <div class="event-grid">
                <?php foreach ($upcomingEvents as $item): ?>
                    <article class="event-card">
                        <div class="date-badge">
                            <span class="month"><?= date('M', strtotime($item['event_date'])) ?></span>
                            <span class="day"><?= date('j', strtotime($item['event_date'])) ?></span>
                        </div>

                        <div>
                            <div class="ann-card-title"><?= htmlspecialchars($item['title']) ?></div>
                            <div class="ann-meta">
                                <span class="ann-meta-pill">Posted <?= date('M j, Y', strtotime($item['created_at'])) ?></span>
                                <?php if (!empty($item['location'])): ?>
                                    <span class="ann-meta-pill">Location: <?= htmlspecialchars($item['location']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="ann-content">
                                <?= nl2br(htmlspecialchars($item['content'])) ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="ann-section fade-in">
        <div class="ann-section-head">
            <div>
                <h2 class="ann-section-title">Latest Announcements</h2>
                <p class="ann-section-desc">General reminders, updates, and posted information for alumni.</p>
            </div>
            <span class="ann-count-pill"><?= count($announcements) ?></span>
        </div>

        <?php if (empty($announcements)): ?>
            <div class="empty-announcements">
                <div class="empty-icon">!</div>
                <div class="empty-title">No announcements yet</div>
                <div>There are no published alumni announcements at the moment. Please check again later.</div>
            </div>
        <?php else: ?>
            <div class="announcement-list">
                <?php foreach ($announcements as $item): ?>
                    <article class="announcement-card">
                        <div class="announcement-top">
                            <div>
                                <div class="ann-card-title"><?= htmlspecialchars($item['title']) ?></div>
                                <div class="ann-meta">
                                    <span class="ann-meta-pill">Posted <?= date('F j, Y', strtotime($item['created_at'])) ?></span>

                                    <?php if (!empty($item['event_date'])): ?>
                                        <span class="ann-meta-pill">Event: <?= date('F j, Y', strtotime($item['event_date'])) ?></span>
                                    <?php endif; ?>

                                    <?php if (!empty($item['location'])): ?>
                                        <span class="ann-meta-pill">Location: <?= htmlspecialchars($item['location']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <span class="type-badge"><?= !empty($item['event_date']) ? 'Event' : 'Announcement' ?></span>
                        </div>

                        <div class="ann-content">
                            <?= nl2br(htmlspecialchars($item['content'])) ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
