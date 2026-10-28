<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? 'Welcome back';
$activePage = $activePage ?? 'dashboard';
$userRole = $_SESSION['role'] ?? '';
$firstName = $_SESSION['first_name'] ?? 'User';
$lastName = $_SESSION['last_name'] ?? '';
$initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));

require_once __DIR__ . '/profile_helpers.php';

// Profile picture for the top-bar avatar (alumni / partner). Loaded once per session, refreshed when the profile is saved.
if (!isset($_SESSION['profile_photo'])) {
    $_SESSION['profile_photo'] = '';
    if (isset($pdo) && $pdo instanceof PDO && !empty($_SESSION['user_id'])) {
        try {
            if ($userRole === 'alumni') {
                $photoStmt = $pdo->prepare("SELECT profile_photo FROM alumni_profiles WHERE user_id = ? LIMIT 1");
            } elseif ($userRole === 'partner') {
                $photoStmt = $pdo->prepare("SELECT company_logo FROM partner_profiles WHERE user_id = ? LIMIT 1");
            } else {
                $photoStmt = null;
            }
            if ($photoStmt) {
                $photoStmt->execute([$_SESSION['user_id']]);
                $_SESSION['profile_photo'] = (string) ($photoStmt->fetchColumn() ?: '');
            }
        } catch (Exception $e) {
            $_SESSION['profile_photo'] = '';
        }
    }
}
$headerPhotoUrl = profilePhotoUrl($_SESSION['profile_photo'] ?? '', '../');

$currentFolder = ($userRole === 'alumni') ? 'alumni' : (($userRole === 'partner') ? 'partner' : 'admin');

if (!function_exists('glIcon')) {
    /** Modern line icons (Lucide style, inline SVG, inherits text colour). */
    function glIcon(string $name): string
    {
        static $paths = [
            'dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
            'user' => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
            'megaphone' => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
            'file' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
            'sparkles' => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>',
            'clipboard' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
            'building' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
            'briefcase' => '<path d="M12 12h.01"/><path d="M16 6V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><path d="M22 13a18.15 18.15 0 0 1-20 0"/><rect width="20" height="14" x="2" y="6" rx="2"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'graduation' => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
            'check' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
            'chart' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
            'help' => '<circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/><circle cx="12" cy="12" r="4"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            'menu' => '<line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/>',
            'close' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        ];
        $p = $paths[$name] ?? '';
        return '<svg class="gl-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . $p . '</svg>';
    }
}

function navActive($activePage, $name)
{
    return $activePage === $name ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | GradLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <link href="../assets/css/loader.css" rel="stylesheet">
    <script src="../assets/js/loader.js" defer></script>
    <script src="../assets/js/sidebar.js" defer></script>
</head>

<body class="<?= $userRole === 'partner' ? 'partner-theme' : '' ?>">

    <div class="mobile-top">
        <button type="button" class="mobile-menu-btn" data-sidebar-toggle aria-label="Open menu" aria-controls="appSidebar" aria-expanded="false">
            <?= glIcon('menu') ?>
        </button>
        <div class="mobile-brand">GradLink</div>
        <a href="../logout.php" class="btn btn-light btn-sm rounded-pill fw-semibold">Logout</a>
    </div>
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <div class="dashboard-layout">
        <aside class="sidebar" id="appSidebar" aria-label="Main menu">
            <button type="button" class="sidebar-close" data-sidebar-close aria-label="Close menu"><?= glIcon('close') ?></button>
            <div class="brand-wrap">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <img src="../assets/logo.png" alt="GradLink Logo" width="50px">
                    <div>
                        <h2 class="brand-title">GradLink</h2>
                        <p class="brand-subtitle" style="margin-top: -1px;">
                            <?= $userRole === 'alumni' ? 'Alumni Dashboard' : ($userRole === 'partner' ? 'Industry Partner Portal' : 'Admin Portal') ?>
                        </p>
                    </div>
                </div>

            </div>

            <div class="sidebar-section-title">Navigation</div>
            <ul class="sidebar-nav">
                <li>
                    <a href="dashboard.php" class="<?= navActive($activePage, 'dashboard') ?>">
                        <span class="nav-icon" aria-hidden="true"><?= glIcon('dashboard') ?></span>
                        <span>Dashboard</span>
                    </a>
                </li>

                <?php if ($userRole === 'alumni'): ?>
                    <li>
                        <a href="edit_profile.php" class="<?= navActive($activePage, 'profile') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('user') ?></span>
                            <span>My Profile</span>
                        </a>
                    </li>
                    <li>
                        <a href="announcements.php" class="<?= navActive($activePage, 'announcements') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('megaphone') ?></span>
                            <span>Announcements</span>
                        </a>
                    </li>
                    <li>
                        <a href="applications.php" class="<?= navActive($activePage, 'applications') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('file') ?></span>
                            <span>My Applications</span>
                        </a>
                    </li>
                    <li>
                        <a href="recommended_jobs.php" class="<?= navActive($activePage, 'jobs') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('sparkles') ?></span>
                            <span>Recommended Jobs</span>
                        </a>
                    </li>
                    <li>
                        <a href="surveys.php" class="<?= navActive($activePage, 'surveys') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('clipboard') ?></span>
                            <span>Tracer Survey</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if ($userRole === 'partner'): ?>
                    <li>
                        <a href="edit_profile.php" class="<?= navActive($activePage, 'profile') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('building') ?></span>
                            <span>Company Profile</span>
                        </a>
                    </li>
                    <li>
                        <a href="job_posts.php" class="<?= navActive($activePage, 'jobs') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('briefcase') ?></span>
                            <span>Job Posts</span>
                        </a>
                    </li>
                    <li>
                        <a href="applicants.php" class="<?= navActive($activePage, 'applicants') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('users') ?></span>
                            <span>Applicants</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if ($userRole === 'admin'): ?>
                    <!-- <li>
                        <a href="dashboard.php" class="<?= navActive($activePage, 'dashboard') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('dashboard') ?></span>
                            <span>Dashboard</span>
                        </a>
                    </li> -->
                    <li>
                        <a href="announcements.php" class="<?= navActive($activePage, 'announcements') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('megaphone') ?></span>
                            <span>Announcements</span>
                        </a>
                    </li>
                    <li>
                        <a href="alumni_accounts.php" class="<?= navActive($activePage, 'alumni') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('graduation') ?></span>
                            <span>Alumni Accounts</span>
                        </a>
                    </li>
                    <li>
                        <a href="partner_accounts.php" class="<?= navActive($activePage, 'partners') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('building') ?></span>
                            <span>Industry Partners</span>
                        </a>
                    </li>
                    <li>
                        <a href="job_posts.php" class="<?= navActive($activePage, 'jobs') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('briefcase') ?></span>
                            <span>Job Posts</span>
                        </a>
                    </li>
                    <li>
                        <a href="applications.php" class="<?= navActive($activePage, 'applications') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('check') ?></span>
                            <span>Applications</span>
                        </a>
                    </li>
                    <li>
                        <a href="surveys.php" class="<?= navActive($activePage, 'surveys') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('clipboard') ?></span>
                            <span>Tracer Surveys</span>
                        </a>
                    </li>
                    <li>
                        <a href="tracer_dashboard.php" class="<?= navActive($activePage, 'tracer_dashboard') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= glIcon('chart') ?></span>
                            <span>Tracer Dashboard</span>
                        </a>
                    </li>

                <?php endif; ?>
            </ul>

            <div class="sidebar-section-title">Account</div>
            <ul class="sidebar-nav">
                <li>
                    <a href="help.php" class="<?= navActive($activePage, 'help') ?>">
                        <span class="nav-icon" aria-hidden="true"><?= glIcon('help') ?></span>
                        <span>Help &amp; Support</span>
                    </a>
                </li>
                <li>
                    <a href="../logout.php">
                        <span class="nav-icon" aria-hidden="true"><?= glIcon('logout') ?></span>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </aside>

        <main class="main-panel">
            <header class="topbar">
                <div class="topbar-left">
                    <h1><?= htmlspecialchars($pageTitle) ?></h1>
                    <p><?= htmlspecialchars($pageSubtitle) ?></p>
                </div>

                <div class="topbar-right">
                    <div class="profile-chip">
                        <div class="avatar-circle<?= $headerPhotoUrl ? ' has-photo' : '' ?>">
                            <?php if ($headerPhotoUrl): ?>
                                <img src="<?= htmlspecialchars($headerPhotoUrl) ?>" alt="Profile picture">
                            <?php else: ?>
                                <?= htmlspecialchars($initials) ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-meta">
                            <div class="fw-semibold"><?= htmlspecialchars($firstName . ' ' . $lastName) ?></div>
                            <div class="small text-muted text-capitalize"><?= htmlspecialchars($userRole) ?></div>
                        </div>
                    </div>
                </div>
            </header>

            <section class="content-area">