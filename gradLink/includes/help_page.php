<?php
/**
 * Shared Help & Support page.
 * Included by alumni/help.php, partner/help.php and admin/help.php
 * (each sets $helpRole before including this file).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/support.php';

$helpRole = $helpRole ?? ($_SESSION['role'] ?? 'alumni');
if (!in_array($helpRole, ['alumni', 'partner', 'admin'], true)) {
    $helpRole = 'alumni';
}

function help_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------
   Contact form handling (runs before any HTML is sent)
------------------------------------------------------------------ */
$categories = ['Account & Login', 'Jobs & Applications', 'Surveys', 'Technical Issue', 'Other'];
$errors = [];
$old = ['category' => '', 'subject' => '', 'message' => ''];

$flash = $_SESSION['help_flash'] ?? null;
unset($_SESSION['help_flash']);

if (empty($_SESSION['help_csrf'])) {
    $_SESSION['help_csrf'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['help_action'] ?? '') === 'send_support') {
    $old['category'] = trim($_POST['category'] ?? '');
    $old['subject']  = trim($_POST['subject'] ?? '');
    $old['message']  = trim($_POST['message'] ?? '');

    $userEmail = trim($_SESSION['email'] ?? '');
    $userName  = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

    if (!hash_equals($_SESSION['help_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try sending your message again.';
    }
    if (!in_array($old['category'], $categories, true)) {
        $errors[] = 'Please choose a topic.';
    }
    if ($old['subject'] === '' || strlen($old['subject']) > 120) {
        $errors[] = 'Please enter a subject (up to 120 characters).';
    }
    if (strlen($old['message']) < 10 || strlen($old['message']) > 2000) {
        $errors[] = 'Your message should be between 10 and 2000 characters.';
    }
    if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'We could not find a valid email on your account, so we cannot send replies.';
    }
    if (time() - (int) ($_SESSION['help_last_sent'] ?? 0) < 60) {
        $errors[] = 'Please wait a minute before sending another message.';
    }

    if (!$errors) {
        require_once __DIR__ . '/mailer.php';
        $sent = sendSupportRequest($userEmail, $userName, $helpRole, $old['category'], $old['subject'], $old['message']);

        if ($sent) {
            $_SESSION['help_last_sent'] = time();
            $_SESSION['help_flash'] = 'Message sent! Our team will reply to ' . $userEmail . '.';
            header('Location: help.php#contact');
            exit;
        }
        $errors[] = 'We could not send your message right now. Please try again, or email us directly.';
    }
}

/* ------------------------------------------------------------------
   Content
------------------------------------------------------------------ */
$roleLabel = ['alumni' => 'Alumni', 'partner' => 'Industry Partner', 'admin' => 'Administrator'][$helpRole];

$heroText = [
    'alumni'  => 'Guides, answers and a direct line to the team for everything about your profile, job matches, applications and tracer surveys.',
    'partner' => 'Guides, answers and a direct line to the team for posting jobs, reviewing applicants and managing your company profile.',
    'admin'   => 'Guides, answers and a direct line to the team for managing accounts, announcements, job posts and tracer surveys.',
][$helpRole];

$guides = [
    'alumni' => [
        ['◉', 'Complete your profile', 'Add your details, skills and resume so GradLink can match you with the right jobs.', 'edit_profile.php', 'Edit profile'],
        ['↗', 'Find recommended jobs', 'Browse openings matched to your profile and apply in a few clicks.', 'recommended_jobs.php', 'View jobs'],
        ['▤', 'Track your applications', 'See where each application stands, from submitted to interview.', 'applications.php', 'My applications'],
        ['◌', 'Answer the tracer survey', 'Help the school follow graduate outcomes by completing open surveys.', 'surveys.php', 'Open surveys'],
    ],
    'partner' => [
        ['◉', 'Set up your company profile', 'Tell alumni who you are so your job posts stand out.', 'edit_profile.php', 'Edit profile'],
        ['✦', 'Post a new job', 'Create an opening and describe the role and requirements.', 'create_job.php', 'Create job post'],
        ['↗', 'Review applicants', 'Go through applications and update each applicant\'s status.', 'applicants.php', 'View applicants'],
        ['▤', 'Manage your job posts', 'Edit, close or remove openings at any time.', 'job_posts.php', 'Job posts'],
    ],
    'admin' => [
        ['◉', 'Manage alumni accounts', 'Review, activate or deactivate alumni accounts.', 'alumni_accounts.php', 'Alumni accounts'],
        ['▤', 'Manage industry partners', 'Review and manage partner company accounts.', 'partner_accounts.php', 'Industry partners'],
        ['✦', 'Publish announcements', 'Share news and events with the right audience.', 'announcements.php', 'Announcements'],
        ['◎', 'Open the tracer dashboard', 'Track graduate outcomes and export reports.', 'tracer_dashboard.php', 'Tracer dashboard'],
    ],
][$helpRole];

$faqs = [
    'alumni' => [
        ['Profile', 'How do I update my profile and resume?', 'Open <strong>My Profile</strong> from the sidebar. You can edit your details and upload your resume as a PDF. Keep it current &mdash; your profile and resume are what GradLink uses to find job matches for you.'],
        ['Jobs', 'How are recommended jobs chosen?', 'Recommended Jobs compares open positions against the information in your profile and resume. The more complete they are, the better your matches will be.'],
        ['Jobs', 'How do I apply for a job?', 'Open a job from <strong>Recommended Jobs</strong>, review the details, then choose to apply and attach your resume. Your submission will then appear under <strong>My Applications</strong>.'],
        ['Applications', 'What do my application statuses mean?', 'The company updates your status as it reviews your application. When it changes you also get an email &mdash; for example when an interview is scheduled (with the date and time) or when an application is not moving forward.'],
        ['Applications', 'Where do I find invitations from companies?', 'Companies can invite alumni to apply for their openings. Check your dashboard and your email for invitations.'],
        ['Surveys', 'What is the tracer survey and do I have to answer it?', 'The tracer survey helps the school understand where graduates are today. Your answers shape programs for future students. Open surveys are listed under <strong>Tracer Survey</strong>.'],
        ['Profile', 'How do I update my career information?', 'Use the career update option from your dashboard to add a new job or change your employment status, so your career timeline stays accurate.'],
    ],
    'partner' => [
        ['Jobs', 'How do I post a job?', 'Go to <strong>Job Posts</strong> and choose to create a new post. Add the title, description and requirements, then save it. Open posts are visible to matching alumni.'],
        ['Jobs', 'Can I edit or close a job post later?', 'Yes. From <strong>Job Posts</strong> you can edit the details, change its status or remove it whenever you need to.'],
        ['Applicants', 'How do I review and update an applicant?', 'Open <strong>Applicants</strong>, select an application and update its status. You can add notes, and applicants receive an email whenever their status changes.'],
        ['Applicants', 'How do I schedule an interview?', 'Set the applicant\'s status to <strong>Interview Scheduled</strong> and choose a date and time. They will be emailed the schedule automatically.'],
        ['Matches', 'What are Top Matches and invitations?', 'Top Matches ranks alumni who fit a specific job post. From there you can invite promising alumni to apply directly.'],
        ['Profile', 'How do I update my company profile?', 'Open <strong>Company Profile</strong> in the sidebar to edit your company details so alumni know who they are applying to.'],
        ['Jobs', 'Why can\'t alumni see my job post?', 'Check its status under <strong>Job Posts</strong> &mdash; closed or inactive posts are hidden. Administrators can also change a post\'s status. If it is open and still missing, contact support below.'],
    ],
    'admin' => [
        ['Accounts', 'How do I activate or deactivate an account?', 'Go to <strong>Alumni Accounts</strong> or <strong>Industry Partners</strong>, find the user and change their status. The change takes effect the next time they sign in.'],
        ['Announcements', 'How do I publish an announcement or event?', 'Open <strong>Announcements</strong> and create a new one. Choose the audience, and add an event date and location if it is an event. Only published items are shown to users.'],
        ['Surveys', 'How do I create and manage tracer surveys?', 'Use <strong>Tracer Surveys</strong> to create, edit or remove surveys, and to read individual responses and survey analytics.'],
        ['Surveys', 'How do I export a tracer report?', 'Open the <strong>Tracer Dashboard</strong> and use the export option to download the current tracer report.'],
        ['Jobs', 'Can I moderate job posts and applications?', 'Yes. <strong>Job Posts</strong> and <strong>Applications</strong> let you review everything on the platform, update statuses and remove entries that should not be there.'],
    ],
][$helpRole];

$faqs = array_merge($faqs, [
    ['Account', 'I did not receive my verification code', 'Check your spam or junk folder first, then use the resend option on the verification screen. Codes expire after 5 minutes, so always use the newest one.'],
    ['Account', 'I cannot sign in or my account is not active', 'Some accounts need to be approved, and an administrator can deactivate an account. If you believe this is a mistake, send us a message below with the email you registered with.'],
    ['Technical', 'A page is not loading or looks broken', 'Refresh the page, clear your browser cache or try another browser. If the problem continues, tell us which page it happened on and what you were doing.'],
]);

$faqCategories = array_values(array_unique(array_column($faqs, 0)));

$pageTitle = 'Help & Support';
$pageSubtitle = 'Find answers, learn how GradLink works, or get in touch with the team.';
$activePage = 'help';

require_once __DIR__ . '/dashboard_header.php';
?>

<style>
    .hs-page {
        --hs-maroon: var(--maroon, #800000);
        --hs-dark: var(--dark-maroon, #5c0000);
        --hs-soft: #fff5f5;
        --hs-border: #f0dddd;
        --hs-text: #2b2020;
        --hs-muted: #7b6b6b;
    }

    @supports (background: color-mix(in srgb, red 10%, white)) {
        .hs-page {
            --hs-soft: color-mix(in srgb, var(--maroon, #800000) 8%, #fff);
            --hs-border: color-mix(in srgb, var(--maroon, #800000) 14%, #fff);
        }
    }

    .hs-page [hidden] { display: none !important; }

    /* Hero */
    .hs-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 36px;
        margin-bottom: 24px;
        color: #fff;
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.28), transparent 30%),
            linear-gradient(135deg, var(--hs-maroon), var(--hs-dark));
        box-shadow: 0 18px 45px rgba(128, 0, 0, 0.18);
    }

    .hs-hero::after {
        content: "";
        position: absolute;
        width: 260px;
        height: 260px;
        right: -80px;
        bottom: -120px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.12);
    }

    .hs-hero-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.5fr);
        gap: 24px;
        align-items: stretch;
    }

    .hs-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.2);
        font-size: 13px;
        font-weight: 700;
    }

    .hs-title {
        margin: 18px 0 12px;
        font-size: clamp(28px, 4vw, 42px);
        line-height: 1.08;
        font-weight: 900;
        letter-spacing: -1px;
    }

    .hs-subtitle {
        max-width: 640px;
        margin: 0 0 22px;
        color: rgba(255, 255, 255, 0.85);
        font-size: 16px;
        line-height: 1.7;
    }

    .hs-search {
        position: relative;
        max-width: 560px;
    }

    .hs-search .hs-search-icon {
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        width: 18px;
        height: 18px;
        color: var(--hs-muted);
        pointer-events: none;
    }

    .hs-search input {
        width: 100%;
        border: 0;
        outline: 0;
        border-radius: 18px;
        padding: 16px 18px 16px 48px;
        font-size: 15px;
        color: var(--hs-text);
        background: #fff;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.16);
    }

    .hs-search input:focus { box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.35), 0 14px 34px rgba(0, 0, 0, 0.16); }

    .hs-hero-card {
        height: 100%;
        padding: 22px;
        border-radius: 22px;
        background: rgba(255, 255, 255, 0.13);
        border: 1px solid rgba(255, 255, 255, 0.22);
        backdrop-filter: blur(10px);
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .hs-hero-card .label {
        color: rgba(255, 255, 255, 0.74);
        font-size: 13px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.7px;
    }

    .hs-hero-card .value {
        margin-top: 8px;
        font-size: 17px;
        font-weight: 800;
        word-break: break-word;
    }

    .hs-hero-card .note {
        margin: 10px 0 16px;
        color: rgba(255, 255, 255, 0.82);
        font-size: 14px;
        line-height: 1.55;
    }

    .hs-hero-btn {
        align-self: flex-start;
        display: inline-block;
        padding: 11px 18px;
        border-radius: 14px;
        background: #fff;
        color: var(--hs-maroon);
        font-weight: 700;
        font-size: 14px;
        text-decoration: none;
        transition: 0.2s ease;
    }

    .hs-hero-btn:hover { transform: translateY(-2px); color: var(--hs-dark); }

    /* Sections */
    .hs-section {
        background: #fff;
        border: 1px solid var(--hs-border);
        border-radius: 26px;
        padding: 26px;
        margin-bottom: 24px;
        box-shadow: 0 16px 38px rgba(128, 0, 0, 0.06);
    }

    .hs-section-head { margin-bottom: 20px; }
    .hs-section-head h2 { margin: 0; font-size: 22px; font-weight: 800; color: var(--hs-text); }
    .hs-section-head p { margin: 6px 0 0; color: var(--hs-muted); font-size: 14px; }

    /* Guides */
    .hs-guides {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
    }

    .hs-guide {
        display: flex;
        flex-direction: column;
        padding: 20px;
        border-radius: 20px;
        border: 1px solid var(--hs-border);
        background: linear-gradient(180deg, #fff 0%, var(--hs-soft) 100%);
        text-decoration: none;
        color: var(--hs-text);
        transition: 0.2s ease;
    }

    .hs-guide:hover {
        transform: translateY(-3px);
        border-color: var(--hs-maroon);
        box-shadow: 0 14px 30px rgba(128, 0, 0, 0.1);
        color: var(--hs-text);
    }

    .hs-icon {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        font-size: 18px;
        color: #fff;
        background: linear-gradient(135deg, var(--hs-maroon), var(--hs-dark));
        margin-bottom: 14px;
    }

    .hs-guide h3 { margin: 0 0 6px; font-size: 16px; font-weight: 800; }
    .hs-guide p { margin: 0 0 14px; color: var(--hs-muted); font-size: 13.5px; line-height: 1.55; flex: 1; }
    .hs-guide .go { color: var(--hs-maroon); font-weight: 700; font-size: 13.5px; }

    /* FAQ */
    .hs-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }

    .hs-chip {
        border: 1px solid var(--hs-border);
        background: #fff;
        color: var(--hs-text);
        border-radius: 999px;
        padding: 8px 14px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: 0.2s ease;
    }

    .hs-chip:hover { border-color: var(--hs-maroon); color: var(--hs-maroon); }
    .hs-chip.active { background: var(--hs-maroon); border-color: var(--hs-maroon); color: #fff; }

    .hs-faq { display: grid; gap: 12px; }

    .hs-faq details {
        border: 1px solid var(--hs-border);
        border-radius: 18px;
        background: #fff;
        transition: 0.2s ease;
    }

    .hs-faq details[open] {
        background: var(--hs-soft);
        border-color: var(--hs-maroon);
    }

    .hs-faq summary {
        list-style: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 20px;
        font-weight: 700;
        color: var(--hs-text);
    }

    .hs-faq summary::-webkit-details-marker { display: none; }

    .hs-faq .hs-q-meta { display: block; font-size: 11.5px; font-weight: 800; letter-spacing: 0.6px; text-transform: uppercase; color: var(--hs-maroon); margin-bottom: 4px; }

    .hs-plus {
        flex: 0 0 30px;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        font-size: 18px;
        color: var(--hs-maroon);
        background: var(--hs-soft);
        transition: transform 0.2s ease;
    }

    .hs-faq details[open] .hs-plus { transform: rotate(45deg); background: #fff; }

    .hs-answer { padding: 0 20px 20px; color: #4a3d3d; font-size: 14.5px; line-height: 1.75; }

    .hs-empty {
        text-align: center;
        padding: 36px 20px;
        border: 1px dashed var(--hs-border);
        border-radius: 20px;
        color: var(--hs-muted);
    }

    .hs-empty strong { display: block; color: var(--hs-text); font-size: 17px; margin-bottom: 6px; }

    /* Contact */
    .hs-contact-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(260px, 0.6fr);
        gap: 22px;
    }

    .hs-form label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--hs-text); }

    .hs-form .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .hs-form .field { margin-bottom: 14px; }

    .hs-form input,
    .hs-form select,
    .hs-form textarea {
        width: 100%;
        border: 1px solid var(--hs-border);
        border-radius: 14px;
        padding: 12px 14px;
        font-size: 14.5px;
        background: #fff;
        color: var(--hs-text);
        outline: 0;
        transition: 0.2s ease;
        font-family: inherit;
    }

    .hs-form textarea { min-height: 150px; resize: vertical; }

    .hs-form input:focus,
    .hs-form select:focus,
    .hs-form textarea:focus {
        border-color: var(--hs-maroon);
        box-shadow: 0 0 0 4px var(--hs-soft);
    }

    .hs-form-foot { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
    .hs-form-foot small { color: var(--hs-muted); }

    .hs-alert { border-radius: 16px; padding: 14px 16px; margin-bottom: 18px; font-size: 14px; }
    .hs-alert.ok { background: #effaf2; border: 1px solid #bfe6c9; color: #1d6b36; }
    .hs-alert.err { background: #fff1f1; border: 1px solid #f1c3c3; color: #8a1f1f; }
    .hs-alert ul { margin: 0; padding-left: 18px; }

    .hs-side { display: grid; gap: 14px; align-content: start; }

    .hs-side-card {
        border: 1px solid var(--hs-border);
        border-radius: 20px;
        padding: 18px;
        background: linear-gradient(180deg, #fff 0%, var(--hs-soft) 100%);
    }

    .hs-side-card .label { font-size: 12px; font-weight: 800; letter-spacing: 0.6px; text-transform: uppercase; color: var(--hs-muted); }
    .hs-side-card .value { margin-top: 6px; font-weight: 800; color: var(--hs-text); word-break: break-word; }
    .hs-side-card .value a { color: var(--hs-maroon); text-decoration: none; }
    .hs-side-card p { margin: 6px 0 0; font-size: 13.5px; color: var(--hs-muted); line-height: 1.55; }

    @media (max-width: 1100px) {
        .hs-guides { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 991px) {
        .hs-hero-grid,
        .hs-contact-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 576px) {
        .hs-hero { padding: 24px; border-radius: 22px; }
        .hs-section { padding: 18px; border-radius: 22px; }
        .hs-guides,
        .hs-form .row2 { grid-template-columns: 1fr; }
    }
</style>

<div class="hs-page">

    <!-- Hero -->
    <section class="hs-hero">
        <div class="hs-hero-grid">
            <div>
                <span class="hs-eyebrow">? &nbsp;Help &amp; Support &middot; <?= help_h($roleLabel) ?></span>
                <h2 class="hs-title">How can we help you today?</h2>
                <p class="hs-subtitle"><?= help_h($heroText) ?></p>

                <div class="hs-search">
                    <svg class="hs-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="hsSearch" placeholder="Search for answers, e.g. &ldquo;resume&rdquo; or &ldquo;status&rdquo;" autocomplete="off" aria-label="Search help articles">
                </div>
            </div>

            <div class="hs-hero-card">
                <div class="label">Still stuck?</div>
                <div class="value"><?= help_h(SUPPORT_EMAIL) ?></div>
                <div class="note">Send us a message and we will reply to the email on your account.</div>
                <a href="#contact" class="hs-hero-btn">Contact support</a>
            </div>
        </div>
    </section>

    <!-- Quick guides -->
    <!-- <section class="hs-section">
        <div class="hs-section-head">
            <h2>Get started quickly</h2>
            <p>Jump straight to the pages you will use most.</p>
        </div>

        <div class="hs-guides">
            <?php foreach ($guides as $g): ?>
                <a class="hs-guide" href="<?= help_h($g[3]) ?>">
                    <div class="hs-icon"><?= $g[0] ?></div>
                    <h3><?= help_h($g[1]) ?></h3>
                    <p><?= help_h($g[2]) ?></p>
                    <span class="go"><?= help_h($g[4]) ?> &rarr;</span>
                </a>
            <?php endforeach; ?>
        </div>
    </section> -->

    <!-- FAQ -->
    <section class="hs-section" id="faq">
        <div class="hs-section-head">
            <h2>Frequently asked questions</h2>
            <p id="hsCount">Showing all <?= count($faqs) ?> answers</p>
        </div>

        <div class="hs-chips" id="hsChips">
            <button type="button" class="hs-chip active" data-cat="all">All</button>
            <?php foreach ($faqCategories as $cat): ?>
                <button type="button" class="hs-chip" data-cat="<?= help_h($cat) ?>"><?= help_h($cat) ?></button>
            <?php endforeach; ?>
        </div>

        <div class="hs-faq" id="hsFaq">
            <?php foreach ($faqs as $f): ?>
                <details data-cat="<?= help_h($f[0]) ?>" data-text="<?= help_h(strtolower($f[1] . ' ' . strip_tags($f[2]))) ?>">
                    <summary>
                        <span>
                            <span class="hs-q-meta"><?= help_h($f[0]) ?></span>
                            <?= help_h($f[1]) ?>
                        </span>
                        <span class="hs-plus">+</span>
                    </summary>
                    <div class="hs-answer"><?= $f[2] /* trusted static content above */ ?></div>
                </details>
            <?php endforeach; ?>
        </div>

        <div class="hs-empty" id="hsEmpty" hidden>
            <strong>No matching answers</strong>
            Try different keywords, or send us a message below &mdash; we are happy to help.
        </div>
    </section>

    <!-- Contact -->
    <section class="hs-section" id="contact">
        <div class="hs-section-head">
            <h2>Contact support</h2>
            <p>Can&rsquo;t find what you need? Tell us what is going on.</p>
        </div>

        <div class="hs-contact-grid">
            <form class="hs-form" method="post" action="help.php#contact" novalidate>
                <input type="hidden" name="help_action" value="send_support">
                <input type="hidden" name="csrf" value="<?= help_h($_SESSION['help_csrf']) ?>">

                <?php if ($flash): ?>
                    <div class="hs-alert ok" role="status"><?= help_h($flash) ?></div>
                <?php endif; ?>

                <?php if ($errors): ?>
                    <div class="hs-alert err" role="alert">
                        <ul>
                            <?php foreach ($errors as $e): ?>
                                <li><?= help_h($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="row2">
                    <div class="field">
                        <label for="hsCategory">Topic</label>
                        <select id="hsCategory" name="category" required>
                            <option value="" disabled <?= $old['category'] === '' ? 'selected' : '' ?>>Choose a topic</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= help_h($c) ?>" <?= $old['category'] === $c ? 'selected' : '' ?>><?= help_h($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="hsSubject">Subject</label>
                        <input id="hsSubject" type="text" name="subject" maxlength="120" placeholder="Short summary" value="<?= help_h($old['subject']) ?>" required>
                    </div>
                </div>

                <div class="field">
                    <label for="hsMessage">Message</label>
                    <textarea id="hsMessage" name="message" maxlength="2000" placeholder="Describe the problem and the page where it happened." required><?= help_h($old['message']) ?></textarea>
                </div>

                <div class="hs-form-foot">
                    <small>We will reply to <strong><?= help_h($_SESSION['email'] ?? 'your account email') ?></strong></small>
                    <button type="submit" class="btn-maroon">Send message</button>
                </div>
            </form>

            <aside class="hs-side">
                <div class="hs-side-card">
                    <div class="label">Email us</div>
                    <div class="value"><a href="mailto:<?= help_h(SUPPORT_EMAIL) ?>"><?= help_h(SUPPORT_EMAIL) ?></a></div>
                    <p>Prefer your own email app? Write to us directly.</p>
                </div>
                <div class="hs-side-card">
                    <div class="label">Helpful details</div>
                    <p>Include the page you were on, what you expected to happen and what happened instead. It helps us fix things faster.</p>
                </div>
                <div class="hs-side-card">
                    <div class="label">GradLink</div>
                    <p>Pampanga State University &mdash; Mexico Campus</p>
                </div>
            </aside>
        </div>
    </section>
</div>

<script>
    (function () {
        var search = document.getElementById('hsSearch');
        var chips = document.querySelectorAll('#hsChips .hs-chip');
        var items = document.querySelectorAll('#hsFaq details');
        var empty = document.getElementById('hsEmpty');
        var count = document.getElementById('hsCount');
        var activeCat = 'all';

        function apply() {
            var q = search.value.trim().toLowerCase();
            var shown = 0;

            items.forEach(function (el) {
                var okCat = activeCat === 'all' || el.getAttribute('data-cat') === activeCat;
                var okText = !q || el.getAttribute('data-text').indexOf(q) !== -1;
                var visible = okCat && okText;
                el.hidden = !visible;
                if (visible) shown++;
            });

            empty.hidden = shown !== 0;
            count.textContent = (q || activeCat !== 'all')
                ? 'Showing ' + shown + ' of ' + items.length + ' answers'
                : 'Showing all ' + items.length + ' answers';
        }

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                chips.forEach(function (c) { c.classList.remove('active'); });
                chip.classList.add('active');
                activeCat = chip.getAttribute('data-cat');
                apply();
            });
        });

        search.addEventListener('input', function () {
            if (search.value.trim() !== '') {
                var faq = document.getElementById('faq');
                if (faq && faq.getBoundingClientRect().top > window.innerHeight) {
                    faq.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
            apply();
        });
    })();
</script>

<?php require_once __DIR__ . '/dashboard_footer.php'; ?>
