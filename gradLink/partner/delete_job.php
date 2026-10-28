<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['partner']);

$user_id = $_SESSION['user_id'];
$job_id = $_GET['id'] ?? null;

if ($job_id && ctype_digit($job_id)) {
    $stmt = $pdo->prepare("
        DELETE FROM job_posts
        WHERE id = ? AND partner_id = ?
    ");
    $stmt->execute([$job_id, $user_id]);
}

header('Location: job_posts.php');
exit;