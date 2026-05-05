<?php
// Buyer profile - redirect to shared profile
require_once __DIR__ . '/../config/database.php';
requireRole('buyer');
// Redirect to farmer profile page since it handles both roles
header('Location: ../farmer/profile.php'); exit();
