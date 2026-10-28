<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';
require_once '../includes/profile_ai_helper.php';
require_once '../includes/profile_helpers.php';
require_once '../includes/course_work_match.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];
$errors = [];
$success = '';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalizeUrl($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('/^https?:\/\//i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

function loadProfile(PDO $pdo, int $user_id) {
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.middle_name, u.last_name, u.extension_name, u.email, ap.*
        FROM users u
        LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$profile = loadProfile($pdo, $user_id);

if (!$profile) {
    die('Profile not found.');
}

$coursesWithMajor = ['BSEd', 'BPEd', 'BTLEd', 'BSBA', 'BSIndTech'];

$courseLabels = [
    'BSEd' => 'Bachelor of Secondary Education',
    'BEEd' => 'Bachelor of Elementary Education',
    'BPEd' => 'Bachelor of Physical Education',
    'BTLEd' => 'Bachelor of Technology and Livelihood Education',
    'BSA' => 'Bachelor of Science in Accountancy',
    'BSBA' => 'Bachelor of Science in Business Administration',
    'BSIT' => 'Bachelor of Science in Information Technology',
    'BSHM' => 'Bachelor of Science in Hospitality Management',
    'BSIndTech' => 'Bachelor of Science in Industrial Technology'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save_profile') === 'generate_ai_summary') {
    try {
        $aiResult = generateAiProfileSummary($profile);

        $summaryStmt = $pdo->prepare("
            UPDATE alumni_profiles
            SET ai_profile_summary = ?,
                ai_career_tags = ?
            WHERE user_id = ?
        ");
        $summaryStmt->execute([
            $aiResult['summary'] ?? '',
            $aiResult['tags'] ?? '',
            $user_id
        ]);

        $success = $aiResult['message'] ?? 'AI career summary generated successfully.';
        $profile = loadProfile($pdo, $user_id);
    } catch (Exception $e) {
        $errors[] = 'Something went wrong while generating your AI career summary.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save_profile') === 'save_profile') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $extension_name = cleanExtensionName($_POST['extension_name'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $major = trim($_POST['major'] ?? '');
    $graduation_year = trim($_POST['graduation_year'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $municipality = trim($_POST['municipality'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $street_address = trim($_POST['street_address'] ?? '');
    $subdivision = trim($_POST['subdivision'] ?? '');
    $employment_status = trim($_POST['employment_status'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $job_position = trim($_POST['job_position'] ?? '');
    $industry_type = trim($_POST['industry_type'] ?? '');
    $company_location = trim($_POST['company_location'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $preferred_job_titles = trim($_POST['preferred_job_titles'] ?? '');
    $career_interests = trim($_POST['career_interests'] ?? '');
    $certifications = trim($_POST['certifications'] ?? '');
    $work_experience = trim($_POST['work_experience'] ?? '');
    $preferred_location = trim($_POST['preferred_location'] ?? '');
    $preferred_work_setup = trim($_POST['preferred_work_setup'] ?? '');
    $expected_salary = trim($_POST['expected_salary'] ?? '');
    $available_start_date = trim($_POST['available_start_date'] ?? '');
    $linkedin_url = normalizeUrl($_POST['linkedin_url'] ?? '');
    $portfolio_url = normalizeUrl($_POST['portfolio_url'] ?? '');

    if ($first_name === '' || $last_name === '') {
        $errors[] = 'First name and last name are required.';
    }

    if ($student_id === '' || $course === '' || $graduation_year === '') {
        $errors[] = 'Student ID, course, and graduation year are required.';
    }

    if (in_array($course, $coursesWithMajor, true) && $major === '') {
        $errors[] = 'Please select a major for your course.';
    }

    if (!empty($graduation_year) && !preg_match('/^\d{4}$/', $graduation_year)) {
        $errors[] = 'Graduation year must be a valid 4-digit year.';
    }

    // Current work details are only kept while the alumni is working.
    if (isWorkingStatus($employment_status)) {
        if ($company_name === '' || $job_position === '') {
            $errors[] = 'Please enter your company or business name and your job position.';
        }
        if ($industry_type !== '' && !in_array($industry_type, ALUMNI_INDUSTRIES, true)) {
            $industry_type = '';
        }
    } else {
        $company_name = $job_position = $industry_type = $company_location = '';
    }

    if ($linkedin_url !== '' && !filter_var($linkedin_url, FILTER_VALIDATE_URL)) {
        $errors[] = 'LinkedIn URL must be a valid link.';
    }

    if ($portfolio_url !== '' && !filter_var($portfolio_url, FILTER_VALIDATE_URL)) {
        $errors[] = 'Portfolio URL must be a valid link.';
    }

    if ($available_start_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $available_start_date)) {
        $errors[] = 'Available start date must be a valid date.';
    }

    // Address: province, city/municipality and barangay go together.
    $anyAddress = ($province !== '' || $municipality !== '' || $barangay !== '' || $street_address !== '' || $subdivision !== '');
    if ($anyAddress) {
        if ($province === '' || $municipality === '' || $barangay === '') {
            $errors[] = 'Please select your province, city/municipality, and barangay.';
        } elseif (!validatePhAddress($province, $municipality, $barangay)) {
            $errors[] = 'The selected province, city/municipality, and barangay do not match. Please select them again.';
        }
        $address = composeAddress($street_address, $subdivision, $barangay, $municipality, $province);
    } else {
        // Nothing entered: keep an old free-text address unless a structured one existed before.
        $address = empty($profile['province']) ? trim((string) ($profile['address'] ?? '')) : '';
    }

    // Profile picture (alumni_profiles.profile_photo)
    $oldPhoto = (string) ($profile['profile_photo'] ?? '');
    $removePhoto = !empty($_POST['remove_photo']);
    $newPhoto = null;
    if (empty($errors)) {
        $upload = handleProfilePhotoUpload($_FILES['profile_photo'] ?? [], 'alumni', (int) $user_id);
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

            $profileValues = [
                $student_id,
                $course,
                $major !== '' ? $major : null,
                $graduation_year,
                $phone !== '' ? $phone : null,
                $province !== '' ? $province : null,
                $municipality !== '' ? $municipality : null,
                $barangay !== '' ? $barangay : null,
                $street_address !== '' ? $street_address : null,
                $subdivision !== '' ? $subdivision : null,
                $address !== '' ? $address : null,
                $photoValue,
                $employment_status !== '' ? $employment_status : null,
                $company_name !== '' ? $company_name : null,
                $job_position !== '' ? $job_position : null,
                $industry_type !== '' ? $industry_type : null,
                $company_location !== '' ? $company_location : null,
                $bio !== '' ? $bio : null,
                $skills !== '' ? $skills : null,
                $preferred_job_titles !== '' ? $preferred_job_titles : null,
                $career_interests !== '' ? $career_interests : null,
                $certifications !== '' ? $certifications : null,
                $work_experience !== '' ? $work_experience : null,
                $preferred_location !== '' ? $preferred_location : null,
                $preferred_work_setup !== '' ? $preferred_work_setup : null,
                $expected_salary !== '' ? $expected_salary : null,
                $available_start_date !== '' ? $available_start_date : null,
                $linkedin_url !== '' ? $linkedin_url : null,
                $portfolio_url !== '' ? $portfolio_url : null
            ];

            if (!empty($profile['id'])) {
                $profileStmt = $pdo->prepare("
                    UPDATE alumni_profiles
                    SET student_id = ?,
                        course = ?,
                        major = ?,
                        graduation_year = ?,
                        phone = ?,
                        province = ?,
                        municipality = ?,
                        barangay = ?,
                        street_address = ?,
                        subdivision = ?,
                        address = ?,
                        profile_photo = ?,
                        employment_status = ?,
                        company_name = ?,
                        job_position = ?,
                        industry_type = ?,
                        company_location = ?,
                        bio = ?,
                        skills = ?,
                        preferred_job_titles = ?,
                        career_interests = ?,
                        certifications = ?,
                        work_experience = ?,
                        preferred_location = ?,
                        preferred_work_setup = ?,
                        expected_salary = ?,
                        available_start_date = ?,
                        linkedin_url = ?,
                        portfolio_url = ?,
                        ai_profile_summary = NULL,
                        ai_career_tags = NULL
                    WHERE user_id = ?
                ");
                $profileStmt->execute(array_merge($profileValues, [$user_id]));
            } else {
                $profileStmt = $pdo->prepare("
                    INSERT INTO alumni_profiles (
                        user_id, student_id, course, major, graduation_year, phone,
                        province, municipality, barangay, street_address, subdivision,
                        address, profile_photo,
                        employment_status, company_name, job_position, industry_type, company_location,
                        bio, skills, preferred_job_titles, career_interests,
                        certifications, work_experience, preferred_location, preferred_work_setup,
                        expected_salary, available_start_date, linkedin_url, portfolio_url,
                        ai_profile_summary, ai_career_tags
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
                ");
                $profileStmt->execute(array_merge([$user_id], $profileValues));
            }

            $pdo->commit();

            if ($newPhoto || $removePhoto) {
                deleteProfilePhotoFile($oldPhoto);
            }

            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['profile_photo'] = $photoValue ?? '';

            $success = 'Profile updated successfully. Generate a new AI career summary so your job recommendations stay updated.';
            $profile = loadProfile($pdo, $user_id);
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

$fieldsToCheck = [
    'Student ID' => $profile['student_id'] ?? '',
    'Course' => $profile['course'] ?? '',
    'Graduation Year' => $profile['graduation_year'] ?? '',
    'Phone' => $profile['phone'] ?? '',
    'Address' => $profile['address'] ?? '',
    'Employment Status' => $profile['employment_status'] ?? '',
    'Bio' => $profile['bio'] ?? '',
    'Skills' => $profile['skills'] ?? '',
    'Preferred Job Titles' => $profile['preferred_job_titles'] ?? '',
    'Career Interests' => $profile['career_interests'] ?? '',
    'Work Experience' => $profile['work_experience'] ?? '',
    'Preferred Location' => $profile['preferred_location'] ?? '',
    'Preferred Work Setup' => $profile['preferred_work_setup'] ?? ''
];

if (in_array(($profile['course'] ?? ''), $coursesWithMajor, true)) {
    $fieldsToCheck['Major'] = $profile['major'] ?? '';
}

$filled = 0;
$missingLabels = [];
foreach ($fieldsToCheck as $label => $field) {
    if (!empty(trim((string)$field))) {
        $filled++;
    } else {
        $missingLabels[] = $label;
    }
}
$completion = count($fieldsToCheck) > 0 ? round(($filled / count($fieldsToCheck)) * 100) : 0;
$completionLabel = $completion >= 90 ? 'Ready for matching' : ($completion >= 70 ? 'Almost ready' : 'Needs more details');

$skillsList = array_filter(array_map('trim', preg_split('/[,\n]+/', (string)($profile['skills'] ?? ''))));
$tagList = array_filter(array_map('trim', explode(',', (string)($profile['ai_career_tags'] ?? ''))));
$displayCourse = $courseLabels[$profile['course'] ?? ''] ?? ($profile['course'] ?? 'Not set');
$displayName = formatFullName($profile['first_name'] ?? '', $profile['middle_name'] ?? '', $profile['last_name'] ?? '', $profile['extension_name'] ?? '');
$displayName = $displayName !== '' ? $displayName : 'Alumni';
$photoUrl = profilePhotoUrl($profile['profile_photo'] ?? '', '../');
$avatarInitials = strtoupper(substr((string) ($profile['first_name'] ?? 'A'), 0, 1) . substr((string) ($profile['last_name'] ?? 'L'), 0, 1));

$pageTitle = 'Edit Profile';
$pageSubtitle = 'Keep your academic, personal, and career profile ready for AI job matching.';
$activePage = 'profile';

require_once '../includes/dashboard_header.php';
?>

<style>
    .profile-modern-page {
        --profile-maroon: var(--maroon, #800000);
        --profile-dark: var(--dark-maroon, #5c0000);
        --profile-soft: rgba(128, 0, 0, 0.08);
        --profile-border: #eadede;
        --profile-muted: #6b7280;
        --profile-ink: #202124;
        --profile-shadow: 0 18px 45px rgba(128, 0, 0, 0.08);
        color: var(--profile-ink);
    }

    .profile-modern-page .profile-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 30px;
        margin-bottom: 24px;
        color: #fff;
        background:
            radial-gradient(circle at top right, rgba(255, 205, 65, 0.24), transparent 32%),
            linear-gradient(135deg, var(--profile-maroon), var(--profile-dark));
        box-shadow: 0 24px 55px rgba(128, 0, 0, 0.18);
    }

    .profile-modern-page .profile-hero::after {
        content: '';
        position: absolute;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        right: -95px;
        bottom: -130px;
        background: rgba(255, 255, 255, 0.10);
    }

    .profile-modern-page .hero-content {
        position: relative;
        z-index: 2;
        display: grid;
        grid-template-columns: minmax(0, 1fr) 330px;
        gap: 24px;
        align-items: center;
    }

    .profile-modern-page .eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 13px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.18);
        font-size: 13px;
        font-weight: 800;
        margin-bottom: 16px;
    }

    .profile-modern-page .hero-title {
        font-size: clamp(28px, 4vw, 42px);
        line-height: 1.05;
        font-weight: 900;
        letter-spacing: -1.1px;
        margin-bottom: 12px;
    }

    .profile-modern-page .hero-text {
        max-width: 760px;
        color: rgba(255, 255, 255, 0.86);
        font-size: 15px;
        line-height: 1.7;
        margin-bottom: 18px;
    }

    .profile-modern-page .hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .profile-modern-page .hero-btn {
        border: 0;
        text-decoration: none;
        border-radius: 15px;
        padding: 11px 15px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
    }

    .profile-modern-page .hero-btn.primary {
        color: var(--profile-maroon);
        background: #fff;
    }

    .profile-modern-page .hero-btn.secondary {
        color: #fff;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.18);
    }

    .profile-modern-page .hero-btn:hover {
        transform: translateY(-2px);
    }

    .profile-modern-page .completion-card {
        position: relative;
        z-index: 2;
        border-radius: 24px;
        padding: 22px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.18);
        backdrop-filter: blur(12px);
    }

    .profile-modern-page .completion-value {
        font-size: 46px;
        font-weight: 900;
        letter-spacing: -1px;
    }

    .profile-modern-page .progress-track {
        height: 11px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        overflow: hidden;
        margin: 12px 0 10px;
    }

    .profile-modern-page .progress-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #f8c51c, #ffffff);
    }

    .profile-modern-page .profile-layout {
        display: grid;
        grid-template-columns: 340px minmax(0, 1fr);
        gap: 24px;
        align-items: start;
    }

    .profile-modern-page .panel-card {
        border-radius: 24px;
        background: #fff;
        border: 1px solid var(--profile-border);
        box-shadow: var(--profile-shadow);
        overflow: hidden;
    }

    .profile-modern-page .panel-body {
        padding: 24px;
    }

    .profile-modern-page .profile-avatar {
        width: 74px;
        height: 74px;
        border-radius: 24px;
        display: grid;
        place-items: center;
        background: linear-gradient(135deg, var(--profile-maroon), var(--profile-dark));
        color: #fff;
        font-size: 26px;
        font-weight: 900;
        box-shadow: 0 14px 30px rgba(128, 0, 0, 0.18);
        margin-bottom: 15px;
    }

    .profile-modern-page .profile-avatar.has-photo {
        overflow: hidden;
        background: #fff;
    }

    .profile-modern-page .profile-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .profile-modern-page .panel-title {
        font-size: 16px;
        font-weight: 900;
        color: #2b1f1f;
        margin-bottom: 4px;
    }

    .profile-modern-page .panel-subtitle {
        color: var(--profile-muted);
        font-size: 13px;
        line-height: 1.6;
    }

    .profile-modern-page .mini-info {
        display: grid;
        gap: 10px;
        margin-top: 18px;
    }

    .profile-modern-page .mini-row {
        padding: 12px;
        border-radius: 16px;
        background: #fcfafa;
        border: 1px solid #f0e1e1;
    }

    .profile-modern-page .mini-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .7px;
        color: #9b6b6b;
        font-weight: 900;
        margin-bottom: 3px;
    }

    .profile-modern-page .mini-value {
        font-weight: 800;
        color: #2b1f1f;
        overflow-wrap: anywhere;
    }

    .profile-modern-page .field-section {
        border-top: 1px solid #f1e4e4;
        padding: 24px;
    }

    .profile-modern-page .field-section:first-child {
        border-top: 0;
    }

    .profile-modern-page .section-head {
        display: flex;
        gap: 14px;
        align-items: flex-start;
        margin-bottom: 18px;
    }

    .profile-modern-page .section-icon {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        display: grid;
        place-items: center;
        border-radius: 15px;
        color: var(--profile-maroon);
        background: var(--profile-soft);
        font-weight: 900;
    }

    .profile-modern-page .section-title {
        font-weight: 900;
        margin-bottom: 2px;
        color: #241f1f;
    }

    .profile-modern-page .section-note {
        font-size: 13px;
        color: var(--profile-muted);
        margin: 0;
    }

    .profile-modern-page .form-label {
        font-size: 13px;
        font-weight: 800;
        color: #372d2d;
        margin-bottom: 7px;
    }

    .profile-modern-page .form-control,
    .profile-modern-page .form-select {
        border-radius: 15px;
        border: 1px solid #e8dada;
        padding: 12px 14px;
        color: #231f20;
        background-color: #fff;
    }

    .profile-modern-page textarea.form-control {
        min-height: 108px;
    }

    .profile-modern-page .form-control:focus,
    .profile-modern-page .form-select:focus {
        border-color: var(--profile-maroon);
        box-shadow: 0 0 0 0.22rem rgba(128, 0, 0, 0.10);
    }

    .profile-modern-page .form-text {
        color: #8b7474;
        font-size: 12px;
    }

    .profile-modern-page .setup-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .profile-modern-page .setup-option input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .profile-modern-page .setup-card {
        min-height: 78px;
        border-radius: 16px;
        padding: 13px;
        border: 1px solid #eadede;
        background: #fff;
        cursor: pointer;
        transition: all .2s ease;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 4px;
    }

    .profile-modern-page .setup-card strong {
        font-size: 13px;
        color: #2d2525;
    }

    .profile-modern-page .setup-card span {
        font-size: 11px;
        color: var(--profile-muted);
    }

    .profile-modern-page .setup-option input:checked + .setup-card {
        border-color: var(--profile-maroon);
        background: linear-gradient(135deg, rgba(128, 0, 0, .08), rgba(128, 0, 0, .02));
        box-shadow: 0 14px 30px rgba(128, 0, 0, 0.10);
    }

    .profile-modern-page .tag-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }

    .profile-modern-page .profile-tag {
        border-radius: 999px;
        background: #fff6f6;
        border: 1px solid #eadede;
        color: var(--profile-maroon);
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 800;
    }

    .profile-modern-page .missing-list {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 12px;
    }

    .profile-modern-page .missing-chip {
        border-radius: 999px;
        background: #fff8e7;
        border: 1px solid #f4dfae;
        color: #8a5b00;
        padding: 6px 9px;
        font-size: 12px;
        font-weight: 800;
    }

    .profile-modern-page .ai-box {
        background: linear-gradient(135deg, #fff8e8, #fff);
        border: 1px solid #f0dfba;
        border-radius: 18px;
        padding: 16px;
        margin-top: 16px;
    }

    .profile-modern-page .alert {
        border-radius: 18px;
        border: 0;
    }

    .profile-modern-page .btn-maroon-modern {
        border: 0;
        border-radius: 15px;
        background: linear-gradient(135deg, var(--profile-maroon), var(--profile-dark));
        color: #fff;
        padding: 12px 18px;
        font-weight: 900;
        box-shadow: 0 12px 28px rgba(128, 0, 0, 0.18);
    }

    .profile-modern-page .btn-maroon-modern:hover {
        color: #fff;
        transform: translateY(-1px);
    }

    .profile-modern-page .btn-soft-modern {
        border-radius: 15px;
        border: 1px solid #eadede;
        background: #fff;
        color: var(--profile-maroon);
        padding: 12px 18px;
        font-weight: 900;
    }

    .profile-modern-page .sticky-save {
        position: sticky;
        bottom: 18px;
        z-index: 20;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 14px;
        margin-top: 20px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.92);
        border: 1px solid #eadede;
        box-shadow: 0 18px 50px rgba(128, 0, 0, 0.12);
        backdrop-filter: blur(12px);
    }

    .profile-modern-page .save-note {
        color: var(--profile-muted);
        font-size: 13px;
    }

    @media (max-width: 1100px) {
        .profile-modern-page .hero-content,
        .profile-modern-page .profile-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .profile-modern-page .profile-hero,
        .profile-modern-page .panel-body,
        .profile-modern-page .field-section {
            padding: 20px;
        }

        .profile-modern-page .setup-grid {
            grid-template-columns: 1fr 1fr;
        }

        .profile-modern-page .sticky-save {
            align-items: stretch;
            flex-direction: column;
        }

        .profile-modern-page .sticky-save .btn {
            width: 100%;
        }
    }
</style>

<div class="profile-modern-page fade-in">
    <div class="profile-hero">
        <div class="hero-content">
            <div>
                <span class="eyebrow">Profile Center</span>
                <div class="hero-title">Build a stronger alumni profile</div>
                <div class="hero-text">
                    Keep your academic details, career goals, skills, and preferences updated so the system can recommend better jobs and help partner companies understand your fit.
                </div>
                <div class="hero-actions">
                    <a href="dashboard.php" class="hero-btn primary">Back to Dashboard</a>
                    <a href="recommended_jobs.php" class="hero-btn secondary">View Recommended Jobs</a>
                    <a href="job_invitations.php" class="hero-btn secondary">Job Invitations</a>
                </div>
            </div>

            <div class="completion-card">
                <div class="small text-white-50 fw-bold text-uppercase">Profile Completion</div>
                <div class="completion-value"><?= $completion ?>%</div>
                <div class="progress-track">
                    <div class="progress-fill" style="width: <?= $completion ?>%"></div>
                </div>
                <div class="fw-bold"><?= e($completionLabel) ?></div>
                <div class="small text-white-50 mt-1">
                    <?= empty($missingLabels) ? 'Your profile is ready for job matching.' : count($missingLabels) . ' item(s) still need attention.' ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger shadow-sm">
            <div class="fw-bold mb-2">Please fix the following:</div>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success shadow-sm">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <div class="profile-layout">
        <aside class="panel-card">
            <div class="panel-body">
                <div class="profile-avatar <?= $photoUrl ? 'has-photo' : '' ?>">
                    <?php if ($photoUrl): ?>
                        <img src="<?= e($photoUrl) ?>" alt="Profile picture">
                    <?php else: ?>
                        <?= e($avatarInitials) ?>
                    <?php endif; ?>
                </div>
                <div class="panel-title"><?= e($displayName) ?></div>
                <div class="panel-subtitle"><?= e($profile['email'] ?? '') ?></div>

                <div class="mini-info">
                    <div class="mini-row">
                        <div class="mini-label">Course</div>
                        <div class="mini-value"><?= e($displayCourse) ?></div>
                    </div>
                    <div class="mini-row">
                        <div class="mini-label">Employment Status</div>
                        <div class="mini-value"><?= e($profile['employment_status'] ?? 'Not set') ?></div>
                    </div>
                    <div class="mini-row">
                        <div class="mini-label">Preferred Setup</div>
                        <div class="mini-value"><?= e($profile['preferred_work_setup'] ?? 'Not set') ?></div>
                    </div>
                </div>

                <?php if (!empty($missingLabels)): ?>
                    <div class="mt-4">
                        <div class="panel-title">Needs Attention</div>
                        <div class="panel-subtitle">Complete these fields to improve your profile quality.</div>
                        <div class="missing-list">
                            <?php foreach (array_slice($missingLabels, 0, 8) as $label): ?>
                                <span class="missing-chip"><?= e($label) ?></span>
                            <?php endforeach; ?>
                            <?php if (count($missingLabels) > 8): ?>
                                <span class="missing-chip">+<?= count($missingLabels) - 8 ?> more</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($skillsList)): ?>
                    <div class="mt-4">
                        <div class="panel-title">Skill Highlights</div>
                        <div class="tag-list">
                            <?php foreach (array_slice($skillsList, 0, 10) as $skill): ?>
                                <span class="profile-tag"><?= e($skill) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="ai-box">
                    <div class="panel-title">AI Career Summary</div>
                    <?php if (!empty($profile['ai_profile_summary'])): ?>
                        <div class="panel-subtitle mt-2"><?= nl2br(e($profile['ai_profile_summary'])) ?></div>
                    <?php else: ?>
                        <div class="panel-subtitle mt-2">No AI summary yet. Save your profile first, then generate a summary.</div>
                    <?php endif; ?>

                    <?php if (!empty($tagList)): ?>
                        <div class="tag-list">
                            <?php foreach ($tagList as $tag): ?>
                                <span class="profile-tag"><?= e($tag) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="mt-3" data-loader-text="Generating your AI career summary… this may take a few seconds.">
                        <input type="hidden" name="action" value="generate_ai_summary">
                        <button type="submit" class="btn btn-soft-modern w-100" formnovalidate>
                            Generate AI Summary
                        </button>
                    </form>
                    <div class="form-text mt-2">After editing skills or experience, save first before generating again.</div>
                </div>
            </div>
        </aside>

        <main class="panel-card">
            <form method="POST" id="profileForm" action="" enctype="multipart/form-data" data-loader-text="Saving your profile…">
                <input type="hidden" name="action" value="save_profile">

                <div class="field-section">
                    <div class="section-head">
                        <div class="section-icon">01</div>
                        <div>
                            <div class="section-title">Basic Information</div>
                            <p class="section-note">This identifies your alumni account and keeps your contact information updated.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <?php
                        $gl_photo_url = $photoUrl;
                        $gl_photo_initials = $avatarInitials;
                        $gl_photo_accent = '#800000';
                        $gl_photo_label = 'Profile Picture';
                        include '../includes/profile_photo_field.php';
                        ?>
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control" value="<?= e($profile['first_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Middle Name <span class="text-muted small">(optional)</span></label>
                            <input type="text" name="middle_name" class="form-control" value="<?= e($profile['middle_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" value="<?= e($profile['last_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Extension <span class="text-muted small">(optional)</span></label>
                            <select name="extension_name" class="form-select">
                                <option value="">None</option>
                                <?php foreach (NAME_EXTENSIONS as $ext): ?>
                                    <option value="<?= e($ext) ?>" <?= (($profile['extension_name'] ?? '') === $ext) ? 'selected' : '' ?>><?= e($ext) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" value="<?= e($profile['email'] ?? '') ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" class="form-control" value="<?= e($profile['phone'] ?? '') ?>" placeholder="Example: 09XX XXX XXXX">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold mb-0">Address</label>
                            <div class="form-text mt-0 mb-1">Your current residence.</div>
                        </div>
                        <?php
                        $gl_addr = [
                            'province' => $profile['province'] ?? '',
                            'municipality' => $profile['municipality'] ?? '',
                            'barangay' => $profile['barangay'] ?? '',
                            'street_address' => $profile['street_address'] ?? '',
                            'subdivision' => $profile['subdivision'] ?? '',
                            'legacy' => $profile['address'] ?? '',
                        ];
                        $gl_addr_base = '../';
                        include '../includes/ph_address_fields.php';
                        ?>
                    </div>
                </div>

                <div class="field-section">
                    <div class="section-head">
                        <div class="section-icon">02</div>
                        <div>
                            <div class="section-title">Academic Information</div>
                            <p class="section-note">Used for alumni records, survey reports, and course-based job matching.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Student ID</label>
                            <input type="text" name="student_id" class="form-control" value="<?= e($profile['student_id'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Graduation Year</label>
                            <input type="text" name="graduation_year" class="form-control" value="<?= e($profile['graduation_year'] ?? '') ?>" placeholder="Example: 2026" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Course</label>
                            <select name="course" id="course" class="form-select" required>
                                <option value="">Select Course</option>
                                <?php foreach ($courseLabels as $code => $label): ?>
                                    <option value="<?= e($code) ?>" <?= (($profile['course'] ?? '') === $code) ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6" id="major-container" style="<?= in_array(($profile['course'] ?? ''), $coursesWithMajor, true) ? '' : 'display:none;' ?>">
                            <label class="form-label">Major / Specialization</label>
                            <select name="major" id="major" class="form-select">
                                <option value="">Select Major</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="field-section">
                    <div class="section-head">
                        <div class="section-icon">03</div>
                        <div>
                            <div class="section-title">Career Status</div>
                            <p class="section-note">Give companies a quick view of your current availability and employment context.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Employment Status</label>
                            <select name="employment_status" class="form-select">
                                <?php
                                    $employmentOptions = ['', 'Student', 'Employed', 'Self-employed', 'Freelance', 'Underemployed', 'Unemployed', 'Further Studies'];
                                ?>
                                <?php foreach ($employmentOptions as $option): ?>
                                    <option value="<?= e($option) ?>" <?= (($profile['employment_status'] ?? '') === $option) ? 'selected' : '' ?>><?= $option === '' ? 'Select Status' : e($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Available Start Date</label>
                            <input type="date" name="available_start_date" class="form-control" value="<?= e($profile['available_start_date'] ?? '') ?>">
                        </div>

                        <div class="col-12" id="workDetails" style="display:none;">
                            <div class="p-3 rounded-4" style="background:#fff8ec;border:1px solid #f1dfb8;">
                                <div class="fw-bold mb-1">Current Work Details</div>
                                <div class="form-text mt-0 mb-3">Used by the school to know if your work is related to your course.</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Company or Business Name <span class="text-danger">*</span></label>
                                        <input type="text" name="company_name" id="company_name" class="form-control" maxlength="150"
                                               value="<?= e($profile['company_name'] ?? '') ?>" placeholder="Example: ABC Technologies Inc.">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Job Position / Designation <span class="text-danger">*</span></label>
                                        <input type="text" name="job_position" id="job_position" class="form-control" maxlength="150"
                                               value="<?= e($profile['job_position'] ?? '') ?>" placeholder="Example: Web Developer, Teacher, Cashier">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Industry / Company Type</label>
                                        <select name="industry_type" class="form-select">
                                            <option value="">Select industry</option>
                                            <?php foreach (ALUMNI_INDUSTRIES as $industry): ?>
                                                <option value="<?= e($industry) ?>" <?= (($profile['industry_type'] ?? '') === $industry) ? 'selected' : '' ?>><?= e($industry) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Company Location</label>
                                        <input type="text" name="company_location" class="form-control" maxlength="255"
                                               value="<?= e($profile['company_location'] ?? '') ?>" placeholder="Example: Angeles City, Pampanga">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <script>
                            (function () {
                                var working = ['employed', 'self-employed', 'freelance', 'underemployed'];
                                var sel = document.querySelector('select[name="employment_status"]');
                                var box = document.getElementById('workDetails');
                                var req = [document.getElementById('company_name'), document.getElementById('job_position')];
                                function sync() {
                                    var on = working.indexOf((sel.value || '').toLowerCase()) !== -1;
                                    box.style.display = on ? '' : 'none';
                                    req.forEach(function (el) { if (el) el.required = on; });
                                }
                                if (sel && box) { sel.addEventListener('change', sync); sync(); }
                            })();
                        </script>
                        <div class="col-12">
                            <label class="form-label">Short Bio</label>
                            <textarea name="bio" class="form-control" rows="4" placeholder="Write a short professional introduction about yourself."><?= e($profile['bio'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="field-section">
                    <div class="section-head">
                        <div class="section-icon">04</div>
                        <div>
                            <div class="section-title">AI Job Matching Profile</div>
                            <p class="section-note">The more specific these fields are, the better your recommended jobs and company invitations will be.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Skills</label>
                            <textarea name="skills" class="form-control" rows="3" placeholder="Example: PHP, MySQL, HTML, CSS, bookkeeping, teaching, customer service"><?= e($profile['skills'] ?? '') ?></textarea>
                            <div class="form-text">Separate skills with commas. This is one of the most important fields for AI matching.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preferred Job Titles</label>
                            <textarea name="preferred_job_titles" class="form-control" rows="3" placeholder="Example: Web Developer, IT Support, Teacher, Marketing Assistant"><?= e($profile['preferred_job_titles'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Career Interests</label>
                            <textarea name="career_interests" class="form-control" rows="3" placeholder="Example: software development, education, business, hospitality"><?= e($profile['career_interests'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Certifications / Trainings</label>
                            <textarea name="certifications" class="form-control" rows="3" placeholder="Example: NC II, TESDA, Google certificate, seminars"><?= e($profile['certifications'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Work Experience</label>
                            <textarea name="work_experience" class="form-control" rows="3" placeholder="Example: 6 months internship as IT support, 1 year sales associate"><?= e($profile['work_experience'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="field-section">
                    <div class="section-head">
                        <div class="section-icon">05</div>
                        <div>
                            <div class="section-title">Job Preferences and Links</div>
                            <p class="section-note">Help the system understand where and how you prefer to work.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Preferred Location</label>
                            <input type="text" name="preferred_location" class="form-control" placeholder="Example: San Pablo City, Laguna, Remote" value="<?= e($profile['preferred_location'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expected Salary</label>
                            <input type="text" name="expected_salary" class="form-control" placeholder="Example: 18,000 - 25,000" value="<?= e($profile['expected_salary'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Preferred Work Setup</label>
                            <?php $selectedSetup = $profile['preferred_work_setup'] ?? ''; ?>
                            <div class="setup-grid">
                                <?php
                                    $setupOptions = [
                                        'Onsite' => 'Office-based role',
                                        'Remote' => 'Work from home',
                                        'Hybrid' => 'Office and remote',
                                        'Flexible' => 'Open to any setup'
                                    ];
                                ?>
                                <?php foreach ($setupOptions as $setup => $hint): ?>
                                    <label class="setup-option">
                                        <input type="radio" name="preferred_work_setup" value="<?= e($setup) ?>" <?= $selectedSetup === $setup ? 'checked' : '' ?>>
                                        <span class="setup-card">
                                            <strong><?= e($setup) ?></strong>
                                            <span><?= e($hint) ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">LinkedIn URL</label>
                            <input type="text" name="linkedin_url" class="form-control" placeholder="linkedin.com/in/yourname" value="<?= e($profile['linkedin_url'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Portfolio URL</label>
                            <input type="text" name="portfolio_url" class="form-control" placeholder="your-portfolio.com" value="<?= e($profile['portfolio_url'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <div class="sticky-save">
        <div>
            <div class="fw-bold">Ready to save your profile?</div>
            <div class="save-note">Saving clears the old AI summary so you can generate an updated one after changes.</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="dashboard.php" class="btn btn-soft-modern">Cancel</a>
            <button type="submit" form="profileForm" class="btn btn-maroon-modern">Save Profile</button>
        </div>
    </div>
</div>

<script>
    const courseSelect = document.getElementById('course');
    const majorSelect = document.getElementById('major');
    const majorContainer = document.getElementById('major-container');
    const savedMajor = "<?= e($profile['major'] ?? '') ?>";

    const majors = {
        "BSEd": [
            "Major in Filipino",
            "Major in English"
        ],
        "BPEd": [
            "Major in School Physical Education"
        ],
        "BTLEd": [
            "Major in Home Economics"
        ],
        "BSBA": [
            "Major in Marketing"
        ],
        "BSIndTech": [
            "Major in Automotive Technology",
            "Major in Electrical Technology",
            "Major in Graphics Technology"
        ]
    };

    function updateMajorOptions() {
        const selected = courseSelect.value;
        majorSelect.innerHTML = '<option value="">Select Major</option>';

        if (majors[selected]) {
            majorContainer.style.display = '';
            majorSelect.required = true;

            majors[selected].forEach(function (major) {
                const option = document.createElement('option');
                option.value = major;
                option.textContent = major;

                if (major === savedMajor) {
                    option.selected = true;
                }

                majorSelect.appendChild(option);
            });
        } else {
            majorContainer.style.display = 'none';
            majorSelect.required = false;
            majorSelect.value = '';
        }
    }

    if (courseSelect && majorSelect && majorContainer) {
        courseSelect.addEventListener('change', updateMajorOptions);
        updateMajorOptions();
    }
</script>

<?php require_once '../includes/dashboard_footer.php'; ?>
