<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$applicationId = $_GET['id'] ?? null;

if ($applicationId && ctype_digit((string)$applicationId)) {
    $stmt = $pdo->prepare("
        DELETE FROM applications
        WHERE id = ?
    ");
    $stmt->execute([$applicationId]);
}

header('Location: applications.php');
exit;