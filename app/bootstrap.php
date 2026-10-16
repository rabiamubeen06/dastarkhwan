<?php
declare(strict_types=1);

define('APP_PATH', __DIR__);

require __DIR__ . '/helpers.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Csrf.php';
require __DIR__ . '/Auth.php';

date_default_timezone_set('Asia/Karachi');
error_reporting(E_ALL);
ini_set('display_errors', config('debug') ? '1' : '0');
ini_set('log_errors', '1');

if (PHP_SAPI !== 'cli') {
    // Security headers
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; frame-ancestors 'none'; form-action 'self'");

    // Safe session cookie: HttpOnly, SameSite, and Secure when the site runs on HTTPS
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('DASTARKHWAN_SID');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();

    // Idle timeout
    $idle = (int) config('session_idle_seconds');
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
} else {
    $_SESSION = [];   // command-line scripts and tests have no real session
}
