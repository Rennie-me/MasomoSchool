<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app.php';

$email = strtolower((string) envValue('ADMIN_EMAIL', ''));
$password = (string) envValue('ADMIN_PASSWORD', '');
$name = (string) envValue('ADMIN_NAME', 'School Administrator');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Set ADMIN_EMAIL and an ADMIN_PASSWORD of at least 12 characters in .env.\n");
    exit(1);
}

$check = db()->prepare('SELECT role FROM users WHERE email=?');
$check->execute([$email]);
if ($check->fetch()) {
    fwrite(STDERR, "An account with that email already exists; no account was changed.\n");
    exit(1);
}

$insert = db()->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'admin')");
$insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
fwrite(STDOUT, "Administrator account created for {$email}.\n");
