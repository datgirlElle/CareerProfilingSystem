<?php

/**
 * Baseline security headers — sent on every response, API and page alike.
 *
 * The Content-Security-Policy here is deliberately not maximally strict.
 * This app relies on Tailwind's CDN build (which injects styles via JS at
 * runtime) and on inline <script> blocks throughout every page — locking
 * those down would mean moving every inline script to external files and
 * adding a nonce system, which is a real refactor, not a header change.
 * What's here still meaningfully restricts things: no scripts/styles from
 * unlisted remote origins, no embedding this site in a frame, no <object>/
 * <embed> plugins, no cross-origin form submissions.
 */
class SecurityHeaders
{
    public static function send(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');

        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data:; "
            . "connect-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none';"
        );

        // Only makes sense once actually served over HTTPS (Render) —
        // sending it over plain local HTTP would be a no-op at best and
        // confusing at worst, so gate it the same way Auth.php gates the
        // Secure cookie flag.
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}