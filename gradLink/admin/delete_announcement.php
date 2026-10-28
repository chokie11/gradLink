<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$id = $_GET['id'] ?? null;

if ($id && ctype_digit((string)$id)) {
    $stmt = $pdo->prepare("
        DELETE FROM announcements
        WHERE id = ?
    ");
    $stmt->execute([$id]);
}

header('Location: announcements.php');
exit;