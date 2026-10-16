<?php
// Usage:  php scripts/create_admin.php "Full Name" email@example.com 03001234567
// Creates an ADMIN account from the command line (the website can only create customers).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit('Run this from the command line only.'); }
require __DIR__ . '/../app/bootstrap.php';

if ($argc < 4) { exit("Usage: php scripts/create_admin.php \"Full Name\" email phone\n"); }
[$script, $name, $email, $phone] = $argv;

echo 'Password (hidden): ';
system('stty -echo 2>/dev/null');
$password = trim((string) fgets(STDIN));
system('stty echo 2>/dev/null');
echo "\n";

$result = Auth::createAdmin([
    'full_name' => $name, 'email' => $email, 'phone' => $phone,
    'password' => $password, 'password_confirm' => $password,
]);
if ($result['ok']) {
    echo "Admin created with user_id {$result['user_id']}.\n";
} else {
    foreach ($result['errors'] as $field => $msg) { echo "- {$field}: {$msg}\n"; }
    exit(1);
}
