<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$userId = $_GET['id'] ?? null;
$newStatus = $_GET['status'] ?? null;
$role = $_GET['role'] ?? 'alumni';

if (!$userId || !ctype_digit((string)$userId)) {
    header('Location: dashboard.php');
    exit;
}

if (!in_array($newStatus, ['active', 'inactive'])) {
    header('Location: dashboard.php');
    exit;
}

if (!in_array($role, ['alumni', 'partner'])) {
    $role = 'alumni';
}

/*
|--------------------------------------------------------------------------
| Update only matching role user
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    UPDATE users
    SET status = ?
    WHERE id = ? AND role = ?
");
$stmt->execute([$newStatus, $userId, $role]);

if ($role === 'partner') {
    header('Location: partner_accounts.php');
    exit;
}

header('Location: alumni_accounts.php');
exit;