<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';

requireRole(['partner']);

$helpRole = 'partner';
require_once '../includes/help_page.php';
