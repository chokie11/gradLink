<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$jobId = $_GET['id'] ?? null;
$newStatus = $_GET['status'] ?? null;

if (!$jobId || !ctype_digit((string)$jobId)) {
    header('Location: job_posts.php');
    exit;
}

if (!in_array($newStatus, ['Open', 'Closed'])) {
    header('Location: job_posts.php');
    exit;
}

$stmt = $pdo->prepare("
    UPDATE job_posts
    SET status = ?
    WHERE id = ?
");
$stmt->execute([$newStatus, $jobId]);

header('Location: job_posts.php');
exit;