<?php
// $host = 'sql300.infinityfree.com';
// $dbname = 'if0_41814114_alumni_system';
// $username = 'if0_41814114';
// $password = 'RI0mPEq5a3tkg0M';

$host = 'localhost';
$dbname = 'alumni_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}