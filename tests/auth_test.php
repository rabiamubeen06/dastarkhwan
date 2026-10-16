<?php
// Run from the project root:  php tests/auth_test.php
// Tests the Auth class directly against your real database, then cleans up after itself.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit('Run from the command line.'); }
require __DIR__ . '/../app/bootstrap.php';

$pdo = Database::pdo();
$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$email = 'test_' . bin2hex(random_bytes(4)) . '@example.com';
$good  = ['full_name' => 'Test User', 'email' => $email, 'phone' => '0300-1234567',
          'password' => 'Secret123', 'password_confirm' => 'Secret123'];

echo "\nREGISTRATION\n";
$r = Auth::register($good);
check('valid registration succeeds', $r['ok'] === true);

$row = $pdo->prepare('SELECT * FROM users WHERE email = ?');
$row->execute([$email]); $u = $row->fetch();
check('phone is stored as 923001234567', $u['phone'] === '923001234567');
check('password is hashed, not plain text', $u['password_hash'] !== 'Secret123' && password_verify('Secret123', $u['password_hash']));
check('role is customer', $u['role'] === 'customer');

check('duplicate email is rejected', Auth::register($good)['ok'] === false);
check('weak password is rejected', Auth::register([...$good, 'email' => 'a' . $email, 'password' => 'abc', 'password_confirm' => 'abc'])['ok'] === false);
check('password without a number is rejected', Auth::register([...$good, 'email' => 'b' . $email, 'password' => 'onlyletters', 'password_confirm' => 'onlyletters'])['ok'] === false);
check('mismatched confirmation is rejected', Auth::register([...$good, 'email' => 'c' . $email, 'password_confirm' => 'Different1'])['ok'] === false);
check('invalid email is rejected', Auth::register([...$good, 'email' => 'not-an-email'])['ok'] === false);
check('invalid phone is rejected', Auth::register([...$good, 'email' => 'd' . $email, 'phone' => '12345'])['ok'] === false);

$hack = Auth::register([...$good, 'email' => 'e' . $email, 'role' => 'admin']);
$st = $pdo->prepare('SELECT role FROM users WHERE email = ?'); $st->execute(['e' . $email]);
check('cannot register as admin by sending role=admin', $hack['ok'] === true && $st->fetchColumn() === 'customer');

echo "\nLOGIN\n";
check('wrong password fails', Auth::attempt($email, 'WrongPass1')['ok'] === false);
check('unknown email fails with the same message', Auth::attempt('nobody@example.com', 'Secret123')['error'] === 'Invalid email or password.');
$_SESSION = [];
$ok = Auth::attempt($email, 'Secret123');
check('correct password logs in', $ok['ok'] === true && ($_SESSION['user']['role'] ?? '') === 'customer');
check('SQL injection in email does not log in', Auth::attempt("' OR '1'='1", 'x')['ok'] === false);

echo "\nLOCKOUT (5 failures in 15 minutes)\n";
$pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
for ($i = 0; $i < Auth::MAX_ATTEMPTS; $i++) Auth::attempt($email, 'WrongPass1');
$locked = Auth::attempt($email, 'Secret123');
check('correct password is refused while locked', $locked['ok'] === false && str_contains($locked['error'], 'Too many'));
$pdo->prepare('UPDATE login_attempts SET attempted_at = DATE_SUB(NOW(), INTERVAL 16 MINUTE) WHERE email = ?')->execute([$email]);
check('lock expires after 15 minutes', Auth::attempt($email, 'Secret123')['ok'] === true);

echo "\nBLOCKED ACCOUNT\n";
$pdo->prepare("UPDATE users SET status = 'blocked' WHERE email = ?")->execute([$email]);
check('blocked user cannot log in', Auth::attempt($email, 'Secret123')['ok'] === false);
$pdo->prepare("UPDATE users SET status = 'active' WHERE email = ?")->execute([$email]);


@unlink(__DIR__ . '/../storage/mail.log');
Auth::requestReset($email);
preg_match('/token=([a-f0-9]{64})/', (string) file_get_contents(__DIR__ . '/../storage/mail.log'), $m2);
$pdo->prepare('UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE token_hash = ?')->execute([hash('sha256', $m2[1])]);
check('expired token is rejected', Auth::findValidReset($m2[1]) === null);
@unlink(__DIR__ . '/../storage/mail.log');
Auth::requestReset('nobody@example.com');
check('unknown email sends nothing (and does not error)', !file_exists(__DIR__ . '/../storage/mail.log'));

// cleanup
$pdo->prepare("DELETE FROM users WHERE email IN (?, ?)")->execute([$email, 'e' . $email]);
$pdo->prepare("DELETE FROM login_attempts WHERE email LIKE 'test\\_%@example.com' OR email IN ('nobody@example.com', \"' or '1'='1\")")->execute();

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
