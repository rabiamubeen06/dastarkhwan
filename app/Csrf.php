<?php
declare(strict_types=1);

/** CSRF protection: every POST form carries a secret token tied to the session. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(): bool
    {
        $sent = $_POST['_csrf'] ?? '';
        return is_string($sent) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
    }

    /** Call at the top of every POST handler. Stops the request if the token is wrong. */
    public static function enforce(): void
    {
        if (!self::verify()) {
            http_response_code(419);
            exit('Invalid or expired form token. Go back, refresh the page and try again.');
        }
    }
}
