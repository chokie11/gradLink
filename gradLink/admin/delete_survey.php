<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$survey_id = $_GET['id'] ?? null;

if ($survey_id && ctype_digit((string)$survey_id)) {
    $stmt = $pdo->prepare("
        DELETE FROM tracer_surveys
        WHERE id = ?
    ");
    $stmt->execute([$survey_id]);
}

header('Location: surveys.php');
exit;