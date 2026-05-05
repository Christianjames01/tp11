<?php
require_once __DIR__ . '/../config/database.php';
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . '/index.php?msg=logged_out');
exit();