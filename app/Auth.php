<?php
declare(strict_types=1);

final class Auth
{
    public const MAX_ATTEMPTS  = 5;    // failed logins allowed ...
    public const LOCK_MINUTES  = 15;   // ... inside this window before lockout
    public const RESET_MINUTES = 30;   // password reset link lifetime

    /* ================= validation ================= */

    /** Accepts 03001234567, 0300-1234567, +92 300 1234567 -> returns 923001234567 or null. */
    public static function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (preg_match('/^03\d{9}$/', $digits)) {
            return '92' . substr($digits, 1);
        }
        if (preg_match('/^923\d{9}$/', $digits)) {
            return $digits;
        }
        return null;
    }

    public static function passwordError(string $password, string $confirm): ?string
    {
        if (strlen($password) < 8)  return 'Password must be at least 8 characters.';
        if (strlen($password) > 72) return 'Password must be at most 72 characters.';
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must contain at least one letter and one number.';
        }
        if ($password !== $confirm) return 'Passwords do not match.';
        return null;
    }

    private static function validate(array $in): array
    {
        $errors = [];
        $name = preg_replace('/\s+/', ' ', trim((string) ($in['full_name'] ?? ''))) ?? '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['full_name'] = 'Enter your full name (2 to 120 characters).';
        }
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $errors['email'] = 'Enter a valid email address.';
        }
        $phone = self::normalizePhone((string) ($in['phone'] ?? ''));
        if ($phone === null) {
            $errors['phone'] = 'Enter a valid Pakistani mobile number, e.g. 03001234567.';
        }
        $pwError = self::passwordError((string) ($in['password'] ?? ''), (string) ($in['password_confirm'] ?? ''));
        if ($pwError !== null) {
            $errors['password'] = $pwError;
        }
        return [$errors, ['full_name' => $name, 'email' => $email, 'phone' => $phone]];
    }

    /* ================= registration ================= */

    private static function insertUser(array $in, string $role): array
    {
        [$errors, $clean] = self::validate($in);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$clean['email']]);
        if ($st->fetchColumn()) {
            return ['ok' => false, 'errors' => ['email' => 'An account with this email already exists.']];
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([
                $clean['full_name'], $clean['email'], $clean['phone'],
                password_hash((string) $in['password'], PASSWORD_DEFAULT), $role,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {   // two people registered the same email at once
                return ['ok' => false, 'errors' => ['email' => 'An account with this email already exists.']];
            }
            throw $e;
        }
        return ['ok' => true, 'user_id' => (int) $pdo->lastInsertId()];
    }

    /** Public sign-up. The role is ALWAYS 'customer' and never read from the form. */
    public static function register(array $in): array
    {
        return self::insertUser($in, 'customer');
    }

    /** Used by scripts/create_admin.php (command line) and the admin accounts page. */
    public static function createAdmin(array $in): array
    {
        return self::insertUser($in, 'admin');
    }

    /* ================= login / logout ================= */

    private static function isLocked(string $email): bool
    {
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE)'
        );
        $st->execute([$email]);
        return (int) $st->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public static function attempt(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $pdo = Database::pdo();

        if (self::isLocked($email)) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Please try again in ' . self::LOCK_MINUTES . ' minutes.'];
        }

        $st = $pdo->prepare('SELECT user_id, full_name, email, password_hash, role, status FROM users WHERE email = ?');
        $st->execute([$email]);
        $user = $st->fetch();

        // Always run password_verify, even for unknown emails, so response time does not reveal which emails exist.
        $hash = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        $valid = password_verify($password, $hash) && $user !== false;

        if (!$valid) {
            $pdo->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')->execute([$email, $ip]);
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }
        if ($user['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This account has been blocked. Please contact the shop.'];
        }

        $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
        }
        self::startSession($user);
        return ['ok' => true, 'role' => $user['role']];
    }

    private static function startSession(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);   // prevents session fixation
        }
        $_SESSION['user'] = ['id' => (int) $user['user_id'], 'name' => $user['full_name'], 'role' => $user['role']];
        $_SESSION['last_activity'] = time();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (PHP_SAPI !== 'cli') {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 3600, 'path' => $p['path'],
                    'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax',
                ]);
            }
            session_destroy();
        }
    }

    /* ================= who is logged in ================= */

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function homeUrl(): string
    {
        return (self::user()['role'] ?? '') === 'admin' ? 'admin/dashboard.php' : 'customer/dashboard.php';
    }

    /** Use at the top of every protected page. Re-checks the database so blocked users are kicked out at once. */
    public static function requireLogin(): array
    {
        $u = self::user();
        if ($u === null) {
            redirect('login.php');
        }
        $st = Database::pdo()->prepare('SELECT user_id, full_name, role, status FROM users WHERE user_id = ?');
        $st->execute([$u['id']]);
        $row = $st->fetch();
        if (!$row || $row['status'] !== 'active') {
            self::logout();
            session_start();
            flash('error', 'Your session has ended. Please log in again.');
            redirect('login.php');
        }
        $_SESSION['user'] = ['id' => (int) $row['user_id'], 'name' => $row['full_name'], 'role' => $row['role']];
        return $_SESSION['user'];
    }

    public static function requireRole(string $role): array
    {
        $u = self::requireLogin();
        if ($u['role'] !== $role) {
            http_response_code(403);
            exit('403 Forbidden: you do not have access to this page.');
        }
        return $u;
    }

    /* ================= password reset ================= */

    /** Always behaves the same from the outside, whether or not the email exists. */
    public static function requestReset(string $email): void
    {
        $email = strtolower(trim($email));
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT user_id, full_name FROM users WHERE email = ? AND status = 'active'");
        $st->execute([$email]);
        $user = $st->fetch();
        if (!$user) {
            return;
        }
        // At most 3 reset emails per hour per user.
        $st = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $st->execute([$user['user_id']]);
        if ((int) $st->fetchColumn() >= 3) {
            return;
        }
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
            ->execute([$user['user_id']]);

        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . self::RESET_MINUTES . ' MINUTE))'
        )->execute([$user['user_id'], hash('sha256', $token)]);   // only the hash is stored

        $link = url('reset-password.php?token=' . $token);
        Mailer::send($email, 'Reset your Dastarkhwan password',
            "Hello {$user['full_name']},\n\nUse this link to choose a new password (valid for "
            . self::RESET_MINUTES . " minutes):\n{$link}\n\nIf you did not ask for this, ignore this email.");
    }

    public static function findValidReset(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT reset_id, user_id FROM password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'
        );
        $st->execute([hash('sha256', $token)]);
        return $st->fetch() ?: null;
    }

    public static function resetPassword(string $token, string $password, string $confirm): array
    {
        $error = self::passwordError($password, $confirm);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        $reset = self::findValidReset($token);
        if ($reset === null) {
            return ['ok' => false, 'error' => 'This reset link is invalid or has expired. Please request a new one.'];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?')->execute([$reset['reset_id']]);
            $pdo->prepare('DELETE FROM login_attempts WHERE email = (SELECT email FROM users WHERE user_id = ?)')
                ->execute([$reset['user_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true];
    }
}