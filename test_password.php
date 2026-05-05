<?php
require_once __DIR__ . '/config/database.php';

// Generate a fresh hash and test it
$fresh = password_hash('secret', PASSWORD_BCRYPT);
echo "Fresh hash: " . $fresh . "<br>";
echo "Fresh verify: " . var_export(password_verify('secret', $fresh), true) . "<br><br>";

// Now update the DB with this fresh hash and test
$pdo = getDBConnection();
$pdo->prepare("UPDATE users SET password = ? WHERE id = 8")->execute([$fresh]);
echo "Updated! Now try logging in with secret.";
?>