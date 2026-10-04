<?php
/**
 * Minimal .env loader — no external dependency, since packagist.org
 * is unreachable from the dev sandbox (see .gitignore note on vendor/).
 */
function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $raw = (string) file_get_contents($path);
    // Windows editors may save as UTF-16 (PowerShell 5 "echo > .env", Notepad
    // "Unicode") or with a UTF-8 BOM; both would otherwise hide every key.
    if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
        $raw = substr($raw, 2);
    }
    // UTF-16 text that is plain ASCII (as .env always is) = ASCII with NUL bytes between.
    $raw = str_replace("\0", '', $raw);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        // Allow DB_PASSWORD="secret" as well as DB_PASSWORD=secret.
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        // Real environment variables (e.g. on Render) win; empty ones don't.
        $existing = getenv($key);
        if ($key !== '' && ($existing === false || $existing === '')) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

loadEnv(__DIR__ . '/../.env');

// The school operates in Philippine time. Without this PHP falls back to UTC
// (the Docker image default), so a "today 3 PM" value from a datetime-local
// input was read as 3 PM UTC = 11 PM Manila and could land on tomorrow.
const APP_TIMEZONE = 'Asia/Manila';
date_default_timezone_set(APP_TIMEZONE);
