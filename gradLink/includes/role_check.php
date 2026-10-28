<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

function requireRole($allowedRoles = []) {
    if (!in_array($_SESSION['role'] ?? '', $allowedRoles)) {
        header("Location: ../redirect.php");
        exit;
    }
}