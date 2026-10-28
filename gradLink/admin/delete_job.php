<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$jobId = $_GET['id'] ?? null;

if ($jobId && ctype_digit((string)$jobId)) {
    $stmt = $pdo->prepare("
        DELETE FROM job_posts
        WHERE id = ?
    ");
    $stmt->execute([$jobId]);
}

header('Location: job_posts.php');
exit;