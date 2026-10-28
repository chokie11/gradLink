<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';
require_once '../includes/profile_helpers.php';

requireRole(['partner']);

$user_id = $_SESSION['user_id'];
$errors = [];
$success = '';

$stmt = $pdo->prepare("
    SELECT u.first_name, u.middle_name, u.last_name, u.extension_name, u.email, pp.*
    FROM users u
    LEFT JOIN partner_profiles pp ON u.id = pp.user_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    die('Profile not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $extension_name = cleanExtensionName($_POST['extension_name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $industry_type = trim($_POST['industry_type'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $municipality = trim($_POST['municipality'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $street_address = trim($_POST['street_address'] ?? '');
    $subdivision = trim($_POST['subdivision'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $company_description = trim($_POST['company_description'] ?? '');

    if ($website !== '' && !preg_match('/^https?:\/\//i', $website)) {
        $website = 'https://' . $website;
    }

    if ($first_name === '' || $last_name === '') {
        $errors[] = 'First name and last name are required.';
    }

    if ($company_name === '' || $industry_type === '' || $contact_person === '') {
        $errors[] = 'Company name, industry type, and contact person are required.';
    }

    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        $errors[] = 'Please enter a valid website URL.';
    }

    // Address: province, city/municipality and barangay go together.
    $anyAddress = ($province !== '' || $municipality !== '' || $barangay !== '' || $street_address !== '' || $subdivision !== '');
    if ($anyAddress) {
        if ($province === '' || $municipality === '' || $barangay === '') {
            $errors[] = 'Please select the province, city/municipality, and barangay of your address.';
        } elseif (!validatePhAddress($province, $municipality, $barangay)) {
            $errors[] = 'The selected province, city/municipality, and barangay do not match. Please select them again.';
        }
        $address = composeAddress($street_address, $subdivision, $barangay, $municipality, $province);
    } else {
        // Nothing entered: keep an old free-text address unless a structured one existed before.
        $address = empty($profile['province']) ? trim((string) ($profile['address'] ?? '')) : '';
    }

    // Profile picture (stored in partner_profiles.company_logo)
    $oldPhoto = (string) ($profile['company_logo'] ?? '');
    $removePhoto = !empty($_POST['remove_photo']);
    $newPhoto = null;
    if (empty($errors)) {
        $upload = handleProfilePhotoUpload($_FILES['profile_photo'] ?? [], 'partner', (int) $user_id);
        if (!$upload['ok']) {
            $errors[] = $upload['error'];
        } else {
            $newPhoto = $upload['filename'];
        }
    }
    $photoValue = $newPhoto ?: ($removePhoto ? null : ($oldPhoto !== '' ? $oldPhoto : null));

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $userStmt = $pdo->prepare("
                UPDATE users
                SET first_name = ?, middle_name = ?, last_name = ?, extension_name = ?
                WHERE id = ?
            ");
            $userStmt->execute([
                $first_name,
                $middle_name !== '' ? $middle_name : null,
                $last_name,
                $extension_name !== '' ? $extension_name : null,
                $user_id
            ]);

            $profileExistsStmt = $pdo->prepare("SELECT id FROM partner_profiles WHERE user_id = ? LIMIT 1");
            $profileExistsStmt->execute([$user_id]);
            $profileId = $profileExistsStmt->fetchColumn();

            if ($profileId) {
                $profileStmt = $pdo->prepare("
                    UPDATE partner_profiles
                    SET company_name = ?, industry_type = ?, contact_person = ?, phone = ?,
                        province = ?, municipality = ?, barangay = ?, street_address = ?, subdivision = ?,
                        address = ?, website = ?, company_description = ?, company_logo = ?
                    WHERE user_id = ?
                ");
                $profileStmt->execute([
                    $company_name,
                    $industry_type,
                    $contact_person,
                    $phone !== '' ? $phone : null,
                    $province !== '' ? $province : null,
                    $municipality !== '' ? $municipality : null,
                    $barangay !== '' ? $barangay : null,
                    $street_address !== '' ? $street_address : null,
                    $subdivision !== '' ? $subdivision : null,
                    $address !== '' ? $address : null,
                    $website !== '' ? $website : null,
                    $company_description !== '' ? $company_description : null,
                    $photoValue,
                    $user_id
                ]);
            } else {
                $profileStmt = $pdo->prepare("
                    INSERT INTO partner_profiles
                    (user_id, company_name, industry_type, contact_person, phone,
                     province, municipality, barangay, street_address, subdivision,
                     address, website, company_description, company_logo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $profileStmt->execute([
                    $user_id,
                    $company_name,
                    $industry_type,
                    $contact_person,
                    $phone !== '' ? $phone : null,
                    $province !== '' ? $province : null,
                    $municipality !== '' ? $municipality : null,
                    $barangay !== '' ? $barangay : null,
                    $street_address !== '' ? $street_address : null,
                    $subdivision !== '' ? $subdivision : null,
                    $address !== '' ? $address : null,
                    $website !== '' ? $website : null,
                    $company_description !== '' ? $company_description : null,
                    $photoValue
                ]);
            }

            $pdo->commit();

            if ($newPhoto || $removePhoto) {
                deleteProfilePhotoFile($oldPhoto);
            }

            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['profile_photo'] = $photoValue ?? '';

            $success = 'Company profile updated successfully.';

            $stmt = $pdo->prepare("
                SELECT u.first_name, u.middle_name, u.last_name, u.extension_name, u.email, pp.*
                FROM users u
                LEFT JOIN partner_profiles pp ON u.id = pp.user_id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->execute([$user_id]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newPhoto) {
                deleteProfilePhotoFile($newPhoto);
            }
            $errors[] = 'Something went wrong while updating your profile.';
        }
    }
}

$formValues = $profile;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $formValues = array_merge($profile, [
        'first_name' => $first_name ?? '',
        'middle_name' => $middle_name ?? '',
        'last_name' => $last_name ?? '',
        'extension_name' => $extension_name ?? '',
        'company_name' => $company_name ?? '',
        'industry_type' => $industry_type ?? '',
        'contact_person' => $contact_person ?? '',
        'phone' => $phone ?? '',
        'address' => $address ?? '',
        'province' => $province ?? '',
        'municipality' => $municipality ?? '',
        'barangay' => $barangay ?? '',
        'street_address' => $street_address ?? '',
        'subdivision' => $subdivision ?? '',
        'website' => $website ?? '',
        'company_description' => $company_description ?? ''
    ]);
}

$fieldsToCheck = [
    $formValues['company_name'] ?? '',
    $formValues['industry_type'] ?? '',
    $formValues['contact_person'] ?? '',
    $formValues['phone'] ?? '',
    $formValues['address'] ?? '',
    $formValues['website'] ?? '',
    $formValues['company_description'] ?? ''
];

$filled = 0;
foreach ($fieldsToCheck as $field) {
    if (!empty(trim((string) $field))) {
        $filled++;
    }
}
$completion = count($fieldsToCheck) > 0 ? round(($filled / count($fieldsToCheck)) * 100) : 0;
$missingCount = count($fieldsToCheck) - $filled;
$isComplete = $completion >= 100;

$initials = strtoupper(substr($formValues['company_name'] ?? 'P', 0, 1));
if (!empty($formValues['company_name'])) {
    $words = preg_split('/\s+/', trim($formValues['company_name']));
    $initials = strtoupper(substr($words[0] ?? 'P', 0, 1) . substr($words[1] ?? '', 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Partner Profile | GradLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        :root {
            --partner-gold: #d08c0c;
            --partner-gold-dark: #be7f09;
            --partner-gold-soft: #e59d18;
            --partner-cream: #fff8e8;
            --partner-cream-2: #fff1cf;
            --partner-border: #f1d79d;
            --partner-ink: #2f260f;
            --partner-muted: #74664c;
            --partner-shadow: 0 24px 70px rgba(126, 80, 0, 0.10);
        }

        body.partner-theme {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(229, 157, 24, 0.18), transparent 34%),
                radial-gradient(circle at bottom right, rgba(208, 140, 12, 0.12), transparent 36%),
                linear-gradient(135deg, #fffdf8 0%, #fff6df 100%);
            color: var(--partner-ink);
        }

        .partner-shell {
            max-width: 1240px;
            margin: 0 auto;
            padding: 30px 18px 42px;
        }

        .partner-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 22px;
        }

        .brand-mini {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-mark {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            color: #fff;
            background: linear-gradient(135deg, var(--partner-gold), var(--partner-gold-dark));
            box-shadow: 0 14px 30px rgba(208, 140, 12, 0.24);
        }

        .brand-mini h1 {
            margin: 0;
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.3px;
        }

        .brand-mini p {
            margin: 0;
            color: var(--partner-muted);
            font-size: 13px;
        }

        .top-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .btn-partner {
            border: 0;
            border-radius: 14px;
            padding: 11px 17px;
            font-weight: 800;
            color: #fff;
            background: linear-gradient(135deg, var(--partner-gold), var(--partner-gold-dark));
            box-shadow: 0 14px 28px rgba(208, 140, 12, 0.23);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: 0.18s ease;
        }

        .btn-partner:hover {
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 18px 35px rgba(208, 140, 12, 0.30);
        }

        .btn-partner-outline {
            border: 1px solid var(--partner-border);
            border-radius: 14px;
            padding: 10px 16px;
            font-weight: 800;
            color: #8a5700;
            background: rgba(255, 255, 255, 0.78);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: 0.18s ease;
        }

        .btn-partner-outline:hover {
            color: #6f4600;
            background: var(--partner-cream-2);
            transform: translateY(-1px);
        }

        .profile-hero {
            border-radius: 30px;
            overflow: hidden;
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.24), transparent 30%),
                linear-gradient(135deg, #d08c0c 0%, #b87506 100%);
            color: #fff;
            box-shadow: var(--partner-shadow);
            position: relative;
            margin-bottom: 22px;
        }

        .profile-hero::after {
            content: "";
            position: absolute;
            inset: auto -90px -120px auto;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 999px;
        }

        .hero-inner {
            position: relative;
            z-index: 1;
            padding: 30px;
            display: grid;
            grid-template-columns: 1.35fr 0.65fr;
            gap: 24px;
            align-items: center;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.24);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.7px;
        }

        .hero-title {
            margin: 14px 0 8px;
            font-size: clamp(30px, 4vw, 48px);
            line-height: 1.02;
            font-weight: 950;
            letter-spacing: -1.4px;
        }

        .hero-text {
            margin: 0;
            max-width: 680px;
            color: rgba(255, 255, 255, 0.86);
            font-size: 15px;
            line-height: 1.7;
        }

        .completion-card {
            background: rgba(255, 255, 255, 0.13);
            border: 1px solid rgba(255, 255, 255, 0.22);
            border-radius: 24px;
            padding: 20px;
            backdrop-filter: blur(10px);
        }

        .completion-label {
            color: rgba(255, 255, 255, 0.78);
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .completion-value {
            font-size: 46px;
            font-weight: 950;
            letter-spacing: -1px;
            line-height: 1;
            margin: 8px 0 12px;
        }

        .progress-track {
            height: 10px;
            background: rgba(255, 255, 255, 0.23);
            border-radius: 999px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #ffffff;
            border-radius: 999px;
            width: 0;
        }

        .completion-note {
            margin-top: 12px;
            color: rgba(255, 255, 255, 0.82);
            font-size: 13px;
            line-height: 1.5;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 330px minmax(0, 1fr);
            gap: 22px;
            align-items: start;
        }

        .side-stack,
        .form-card {
            display: grid;
            gap: 16px;
        }

        .soft-card {
            background: rgba(255, 255, 255, 0.86);
            border: 1px solid rgba(241, 215, 157, 0.95);
            border-radius: 24px;
            padding: 20px;
            box-shadow: 0 18px 45px rgba(126, 80, 0, 0.07);
        }

        .company-avatar {
            width: 72px;
            height: 72px;
            border-radius: 24px;
            background: linear-gradient(135deg, var(--partner-gold), var(--partner-gold-dark));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 950;
            box-shadow: 0 18px 35px rgba(208, 140, 12, 0.22);
            margin-bottom: 14px;
        }

        .company-avatar.has-photo {
            padding: 0;
            overflow: hidden;
            background: #fff;
        }

        .company-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .side-title {
            font-size: 16px;
            font-weight: 900;
            margin: 0 0 4px;
            color: var(--partner-ink);
        }

        .side-muted {
            color: var(--partner-muted);
            font-size: 13px;
            line-height: 1.6;
            margin: 0;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid #f4e3bd;
        }

        .info-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .info-key {
            color: var(--partner-muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            text-align: right;
            font-weight: 800;
            color: var(--partner-ink);
            word-break: break-word;
        }

        .quality-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .quality-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 11px 12px;
            border-radius: 16px;
            background: #fff8e8;
            border: 1px solid #f3deaf;
            font-size: 13px;
            color: var(--partner-muted);
        }

        .check-dot {
            width: 22px;
            height: 22px;
            border-radius: 999px;
            background: #f0b13a;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 22px;
            font-size: 12px;
            font-weight: 900;
        }

        .form-card {
            background: rgba(255, 255, 255, 0.90);
            border: 1px solid rgba(241, 215, 157, 0.98);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--partner-shadow);
        }

        .form-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 4px;
        }

        .form-header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 950;
            letter-spacing: -0.6px;
        }

        .form-header p {
            margin: 4px 0 0;
            color: var(--partner-muted);
            font-size: 14px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #fff1cf;
            color: #8a5700;
            border: 1px solid #f1d79d;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .section-box {
            border: 1px solid #f1d79d;
            border-radius: 22px;
            padding: 20px;
            background: linear-gradient(180deg, #ffffff 0%, #fffdf9 100%);
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 950;
            color: var(--partner-ink);
            margin-bottom: 16px;
        }

        .section-title span {
            width: 32px;
            height: 32px;
            border-radius: 12px;
            background: #fff1cf;
            color: #9f6505;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .form-label {
            font-weight: 850;
            color: var(--partner-ink);
            font-size: 13px;
            margin-bottom: 7px;
        }

        .required-mark {
            color: #b87506;
        }

        .form-control,
        .form-select {
            border-radius: 15px;
            border: 1px solid #e7d2a3;
            padding: 12px 14px;
            font-weight: 650;
            color: var(--partner-ink);
            background: #ffffff;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--partner-gold);
            box-shadow: 0 0 0 0.22rem rgba(208, 140, 12, 0.14);
        }

        textarea.form-control {
            line-height: 1.65;
        }

        .field-help {
            color: var(--partner-muted);
            font-size: 12px;
            margin-top: 6px;
        }

        .alert {
            border: 0;
            border-radius: 18px;
            padding: 14px 16px;
            font-weight: 700;
        }

        .alert-danger {
            color: #7f1d1d;
            background: #fff0f0;
            border: 1px solid #f3c7c7;
        }

        .alert-success {
            color: #23631f;
            background: #f0fff0;
            border: 1px solid #c8eec6;
        }

        .sticky-actions {
            position: sticky;
            bottom: 0;
            z-index: 5;
            padding-top: 14px;
            background: linear-gradient(180deg, rgba(255, 253, 248, 0), rgba(255, 253, 248, 0.96) 36%);
        }

        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 14px;
            border-radius: 22px;
            background: #ffffff;
            border: 1px solid #f1d79d;
            box-shadow: 0 14px 36px rgba(126, 80, 0, 0.08);
        }

        .action-note {
            color: var(--partner-muted);
            font-size: 13px;
            line-height: 1.4;
        }

        @media (max-width: 992px) {
            .hero-inner,
            .content-grid {
                grid-template-columns: 1fr;
            }

            .partner-topbar,
            .form-header,
            .action-bar {
                align-items: stretch;
                flex-direction: column;
            }

            .top-actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 576px) {
            .partner-shell {
                padding: 18px 12px 30px;
            }

            .hero-inner,
            .form-card,
            .soft-card {
                padding: 18px;
                border-radius: 22px;
            }

            .profile-hero {
                border-radius: 24px;
            }

            .top-actions a,
            .top-actions button,
            .btn-partner,
            .btn-partner-outline {
                width: 100%;
            }
        }
    </style>
    <link href="../assets/css/loader.css" rel="stylesheet">
    <script src="../assets/js/loader.js" defer></script>
</head>
<body class="partner-theme">
<div class="partner-shell">
    <div class="partner-topbar">
        <div class="brand-mini">
            <div class="brand-mark">GL</div>
            <div>
                <h1>GradLink Partner Portal</h1>
                <p>Company profile and recruitment readiness</p>
            </div>
        </div>

        <div class="top-actions">
            <a href="dashboard.php" class="btn-partner-outline">← Dashboard</a>
            <a href="job_posts.php" class="btn-partner-outline">Job Posts</a>
            <a href="../logout.php" class="btn-partner-outline">Logout</a>
        </div>
    </div>

    <section class="profile-hero">
        <div class="hero-inner">
            <div>
                <span class="eyebrow">Partner Profile Center</span>
                <h2 class="hero-title">Build a credible company presence.</h2>
                <p class="hero-text">
                    Keep your organization profile updated so alumni can recognize your company,
                    trust your job posts, and respond better to recruitment opportunities.
                </p>
            </div>

            <div class="completion-card">
                <div class="completion-label">Profile Completion</div>
                <div class="completion-value"><?= $completion ?>%</div>
                <div class="progress-track">
                    <div class="progress-fill" style="width: <?= $completion ?>%"></div>
                </div>
                <div class="completion-note">
                    <?= $isComplete ? 'Your company profile is complete and ready for recruitment activity.' : $missingCount . ' profile item' . ($missingCount === 1 ? '' : 's') . ' still need attention.' ?>
                </div>
            </div>
        </div>
    </section>

    <div class="content-grid">
        <aside class="side-stack">
            <div class="soft-card">
                <?php $photoUrl = profilePhotoUrl($formValues['company_logo'] ?? '', '../'); ?>
                <div class="company-avatar <?= $photoUrl ? 'has-photo' : '' ?>">
                    <?php if ($photoUrl): ?>
                        <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Profile picture">
                    <?php else: ?>
                        <?= htmlspecialchars($initials) ?>
                    <?php endif; ?>
                </div>
                <h3 class="side-title"><?= htmlspecialchars($formValues['company_name'] ?? 'Company Profile') ?></h3>
                <p class="side-muted">
                    <?= htmlspecialchars($formValues['industry_type'] ?? 'Industry not set') ?>
                </p>

                <div class="mt-3">
                    <div class="info-row">
                        <div class="info-key">Account Email</div>
                        <div class="info-value"><?= htmlspecialchars($formValues['email'] ?? '—') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-key">Contact</div>
                        <div class="info-value"><?= htmlspecialchars($formValues['contact_person'] ?? '—') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-key">Phone</div>
                        <div class="info-value"><?= htmlspecialchars($formValues['phone'] ?? '—') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-key">Website</div>
                        <div class="info-value">
                            <?= !empty($formValues['website']) ? htmlspecialchars($formValues['website']) : '—' ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="soft-card">
                <h3 class="side-title">Recruitment Profile Tips</h3>
                <p class="side-muted">Better company details make your job posts more trustworthy to alumni candidates.</p>
                <div class="quality-list">
                    <div class="quality-item"><span class="check-dot">✓</span><span>Use your official company name and active contact person.</span></div>
                    <div class="quality-item"><span class="check-dot">✓</span><span>Add a website or page where alumni can verify your organization.</span></div>
                    <div class="quality-item"><span class="check-dot">✓</span><span>Write a short company description that explains your work and hiring needs.</span></div>
                </div>
            </div>
        </aside>

        <main class="form-card">
            <div class="form-header">
                <div>
                    <h2>Edit Company Profile</h2>
                    <p>Update your company details, contact information, and public profile summary.</p>
                </div>
                <span class="status-pill"><?= $isComplete ? 'Complete Profile' : 'Profile Needs Update' ?></span>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong>Please fix the following:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" data-loader-text="Saving your company profile…">
                <div class="section-box">
                    <div class="section-title"><span>01</span> Account Representative</div>
                    <div class="row g-3">
                        <?php
                        $gl_photo_url = $photoUrl;
                        $gl_photo_initials = $initials;
                        $gl_photo_accent = '#d08c0c';
                        $gl_photo_label = 'Profile Picture / Company Logo';
                        include '../includes/profile_photo_field.php';
                        ?>
                        <div class="col-md-6">
                            <label class="form-label">First Name <span class="required-mark">*</span></label>
                            <input type="text" name="first_name" class="form-control"
                                   value="<?= htmlspecialchars($formValues['first_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Middle Name <span class="field-help">(optional)</span></label>
                            <input type="text" name="middle_name" class="form-control"
                                   value="<?= htmlspecialchars($formValues['middle_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Last Name <span class="required-mark">*</span></label>
                            <input type="text" name="last_name" class="form-control"
                                   value="<?= htmlspecialchars($formValues['last_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Extension <span class="field-help">(optional)</span></label>
                            <select name="extension_name" class="form-select">
                                <option value="">None</option>
                                <?php foreach (NAME_EXTENSIONS as $ext): ?>
                                    <option value="<?= htmlspecialchars($ext) ?>" <?= (($formValues['extension_name'] ?? '') === $ext) ? 'selected' : '' ?>><?= htmlspecialchars($ext) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control"
                                   value="<?= htmlspecialchars($formValues['email'] ?? '') ?>" disabled>
                            <div class="field-help">Email changes are handled by the system administrator.</div>
                        </div>
                    </div>
                </div>

                <div class="section-box mt-3">
                    <div class="section-title"><span>02</span> Company Identity</div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Company Name <span class="required-mark">*</span></label>
                            <input type="text" name="company_name" class="form-control"
                                   value="<?= htmlspecialchars($formValues['company_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Industry Type <span class="required-mark">*</span></label>
                            <input type="text" name="industry_type" class="form-control"
                                   value="<?= htmlspecialchars($formValues['industry_type'] ?? '') ?>"
                                   placeholder="Example: IT, Education, Finance" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person <span class="required-mark">*</span></label>
                            <input type="text" name="contact_person" class="form-control"
                                   value="<?= htmlspecialchars($formValues['contact_person'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>

                <div class="section-box mt-3">
                    <div class="section-title"><span>03</span> Contact and Online Presence</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?= htmlspecialchars($formValues['phone'] ?? '') ?>"
                                   placeholder="Company contact number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Website</label>
                            <input type="text" name="website" class="form-control"
                                   value="<?= htmlspecialchars($formValues['website'] ?? '') ?>"
                                   placeholder="https://example.com">
                            <div class="field-help">You may type example.com; the system will add https:// automatically.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold mb-0">Company Address</label>
                            <div class="field-help mb-2">Office address or operating location.</div>
                        </div>
                        <?php
                        $gl_addr = [
                            'province' => $formValues['province'] ?? '',
                            'municipality' => $formValues['municipality'] ?? '',
                            'barangay' => $formValues['barangay'] ?? '',
                            'street_address' => $formValues['street_address'] ?? '',
                            'subdivision' => $formValues['subdivision'] ?? '',
                            'legacy' => $formValues['address'] ?? '',
                        ];
                        $gl_addr_base = '../';
                        include '../includes/ph_address_fields.php';
                        ?>
                    </div>
                </div>

                <div class="section-box mt-3">
                    <div class="section-title"><span>04</span> Company Overview</div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Company Description</label>
                            <textarea name="company_description" class="form-control" rows="6" placeholder="Tell alumni what your company does, what kind of candidates you hire, and why they should apply."><?= htmlspecialchars($formValues['company_description'] ?? '') ?></textarea>
                            <div class="field-help">This helps alumni understand your organization before applying.</div>
                        </div>
                    </div>
                </div>

                <div class="sticky-actions">
                    <div class="action-bar">
                        <div class="action-note">
                            Saving updates your public company profile shown across job posts and recruitment pages.
                        </div>
                        <button type="submit" class="btn-partner">Save Company Profile</button>
                    </div>
                </div>
            </form>
        </main>
    </div>
</div>
</body>
</html>
