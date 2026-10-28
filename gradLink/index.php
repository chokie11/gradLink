<?php
session_start();

header("Refresh: 1; url=" . (isset($_SESSION['user_id']) ? 'redirect.php' : 'login.php'));
?>

<h2 style="text-align:center;margin-top:50px;">Loading GradLink...</h2>