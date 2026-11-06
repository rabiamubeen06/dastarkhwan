<?php
declare(strict_types=1);

/** Read config/config.php. config() returns everything, config('db') one section. */
function config(?string $key = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/config.php';
        if (!is_file($file)) {
            exit('Missing config/config.php. Copy config/config.example.php to config/config.php first.');
        }
        $cfg = require $file;
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

/** Escape text before printing it in HTML (prevents XSS). Use on EVERY value you echo. */
function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) config('base_url'), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/** One-time messages shown on the next page (success, error). */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function pull_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Re-fill a form field after a failed submit (already escaped). */
function old(string $key): string
{
    return e(isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '');
}

function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="field-error">' . e($errors[$key]) . '</div>' : '';
}

function brand_ur(): string
{
    return '<bdi class="ur" lang="ur" dir="rtl">دسترخوان</bdi>';
}

/** Fork and knife logo (inline SVG, takes its colour from the surrounding text). */
function logo_svg(int $size = 28): string
{
    return '<svg class="logo" width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32" fill="none" '
         . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . '<path d="M7 4v6a3 3 0 0 0 3 3 3 3 0 0 0 3-3V4"/><path d="M10 4v9"/><path d="M10 13v15"/>'
         . '<path d="M23 28V4c-3.5 1.5-5 5-5 9 0 2 1 3 3 3h2"/>'
         . '</svg>';
}
/** Format an amount as PKR, e.g. PKR 1,250 or PKR 1,250.50. */
function money(float|int|string $amount): string
{
    $s = number_format((float) $amount, 2);
    return 'PKR ' . (str_ends_with($s, '.00') ? substr($s, 0, -3) : $s);
}
/** Read one value from the settings table (loaded once per request). */
function setting(string $key, string $default = ''): string
{
    static $all = null;
    if ($all === null) {
        $all = Database::pdo()->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $all[$key] ?? $default;
}