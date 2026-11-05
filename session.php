<?php
/**
 * Единая точка старта сессии.
 *
 * Имя cookie своё — GCFILES_SID, а не общий PHPSESSID: на этом же домене
 * (grandeconfidence.ru и поддомены) живёт второй сайт, и браузер мог
 * присылать чужую сессию, из-за чего вход «не срабатывал».
 *
 * Атрибуты задаются здесь, а не только в php.ini: тогда сайт не зависит от
 * настроек хостинга и не ломается, если там окажется SameSite=None без Secure
 * (такие cookie браузеры молча выбрасывают).
 */

if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // Legacy pages still use inline handlers/styles; bootstrap sends a stricter
    // policy on modern pages. This fallback at least stops third-party scripts.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

ini_set('session.name', 'GCFILES_SID');
ini_set('session.use_cookies', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_path', '/');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

// Сессия не должна умирать мгновенно из-за настроек хостинга.
if ((int) ini_get('session.gc_maxlifetime') < 900) {
    ini_set('session.gc_maxlifetime', '86400');
}

// За прокси или напрямую по HTTPS — помечаем cookie защищённой.
$gc_https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if ($gc_https) {
    ini_set('session.cookie_secure', '1');
}

session_start();
