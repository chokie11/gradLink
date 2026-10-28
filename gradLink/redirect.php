<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

if ($role === 'admin') {
    header("Location: admin/dashboard.php");
    exit;
}

if ($role === 'alumni') {
    $stmt = $pdo->prepare("SELECT * FROM alumni_profiles WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    $requiredFields = [
        $profile['student_id'] ?? '',
        $profile['course'] ?? '',
        $profile['graduation_year'] ?? '',
        $profile['phone'] ?? '',
        $profile['address'] ?? ''
    ];

    $filled = 0;
    foreach ($requiredFields as $field) {
        if (!empty(trim((string)$field))) {
            $filled++;
        }
    }

    $completion = round(($filled / count($requiredFields)) * 100);

    if ($completion < 100) {
        $_SESSION['profile_completion'] = $completion;
        header("Location: alumni/edit_profile.php");
        exit;
    }

    header("Location: alumni/dashboard.php");
    exit;
}

if ($role === 'partner') {
    $stmt = $pdo->prepare("SELECT * FROM partner_profiles WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    $requiredFields = [
        $profile['company_name'] ?? '',
        $profile['industry_type'] ?? '',
        $profile['contact_person'] ?? '',
        $profile['phone'] ?? '',
        $profile['address'] ?? ''
    ];

    $filled = 0;
    foreach ($requiredFields as $field) {
        if (!empty(trim((string)$field))) {
            $filled++;
        }
    }

    $completion = round(($filled / count($requiredFields)) * 100);

    if ($completion < 100) {
        $_SESSION['profile_completion'] = $completion;
        header("Location: partner/edit_profile.php");
        exit;
    }

    header("Location: partner/dashboard.php");
    exit;
}

session_destroy();
header("Location: login.php");
exit;