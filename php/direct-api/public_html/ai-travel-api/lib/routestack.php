<?php
/**
 * RouteStack shared helpers for all public API endpoints.
 *
 * Loaded via:
 *   require_once __DIR__ . '/lib/routestack.php';
 *
 * Provides:
 *   rs_bootstrap()        — session, private client, sanity checks
 *   rs_csrf_token()       — generate / retrieve CSRF token
 *   rs_csrf_validate()    — assert token is valid on every mutating request
 *   rs_rate_limit()       — per-IP request throttle (APCu preferred, no-op otherwise)
 *   rs_json_exit()        — send JSON and halt
 *   rs_error_exit()       — send error JSON and halt
 *   rs_string()           — sanitise to string
 *   rs_int()              — sanitise to int
 *   rs_date()             — validate YYYY-MM-DD, return string|null
 *   rs_currency()         — validate ISO 4217, default GBP
 *   rs_child_ages()       — validate child ages array
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap — call once at the top of every endpoint file
// ---------------------------------------------------------------------------

function rs_bootstrap(): void
{
    // Start session with secure, SameSite-strict cookie
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    // Load private client — lives above public_html, never web-accessible
    $clientPath = dirname($_SERVER['DOCUMENT_ROOT']) . '/routestack_private/client.php';

    if (!is_file($clientPath)) {
        rs_error_exit(500,
            'RouteStack client not found. Expected: ' . $clientPath . '. '
            . 'Copy routestack_private/client.php.example to that path and fill in your credentials.'
        );
    }

    require_once $clientPath;

    // Verify the three required constants were defined by config.php
    foreach (['RS_API_KEY', 'RS_API_SECRET', 'RS_BASE_URL'] as $c) {
        if (!defined($c)) {
            rs_error_exit(500, $c . ' is not defined. Check routestack_private/config.php.');
        }
    }
    if (RS_API_KEY === 'your-api-key-here' || RS_API_SECRET === 'your-api-secret-here') {
        rs_error_exit(500, 'RS_API_KEY / RS_API_SECRET are still placeholder values. Edit config.php.');
    }
}

// ---------------------------------------------------------------------------
// CSRF helpers
// ---------------------------------------------------------------------------

function rs_csrf_token(): string
{
    if (empty($_SESSION['_rs_csrf'])) {
        $_SESSION['_rs_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_rs_csrf'];
}

function rs_csrf_validate(): void
{
    $token    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['_rs_csrf']         ?? '';

    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        rs_error_exit(403, 'CSRF token invalid or missing.');
    }
}

// ---------------------------------------------------------------------------
// Rate limiting — 60 requests per IP per minute
// Requires APCu extension; silently skipped if unavailable.
// ---------------------------------------------------------------------------

function rs_rate_limit(int $maxPerMinute = 60): void
{
    if (!function_exists('apcu_fetch')) {
        return;
    }

    $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $window  = (int)(time() / 60);           // integer minute bucket
    $key     = 'rs_rl_' . md5($ip) . '_' . $window;
    $ttl     = 61;

    $count = apcu_fetch($key, $found);
    if (!$found) {
        apcu_store($key, 1, $ttl);
        return;
    }

    if ((int)$count >= $maxPerMinute) {
        rs_error_exit(429, 'Too many requests. Please wait a minute and try again.');
    }

    apcu_inc($key);
}

// ---------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------

function rs_json_exit(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rs_error_exit(int $status, string $message): never
{
    rs_json_exit(['success' => false, 'message' => $message], $status);
}

// ---------------------------------------------------------------------------
// Input sanitisers
// ---------------------------------------------------------------------------

function rs_string(mixed $v, string $default = ''): string
{
    return is_string($v) ? trim($v) : $default;
}

function rs_int(mixed $v, int $default = 0): int
{
    if (filter_var($v, FILTER_VALIDATE_INT) !== false) {
        return (int)$v;
    }
    return $default;
}

function rs_date(mixed $v): ?string
{
    if (!is_string($v)) {
        return null;
    }
    $clean = trim($v);
    $d     = \DateTime::createFromFormat('Y-m-d', $clean);
    return ($d && $d->format('Y-m-d') === $clean) ? $clean : null;
}

/**
 * Validate an ISO 4217 currency code; default to GBP.
 */
function rs_currency(mixed $v): string
{
    static $allowed = [
        'GBP', 'USD', 'EUR', 'AUD', 'CAD', 'CHF',
        'SEK', 'NOK', 'DKK', 'JPY', 'INR', 'AED',
        'SGD', 'HKD', 'NZD',
    ];
    $clean = strtoupper(trim(is_string($v) ? $v : ''));
    return in_array($clean, $allowed, true) ? $clean : 'GBP';
}

/**
 * Validate and return an array of child ages (0–17).
 * Returns an empty array if $raw is not an array or has no valid entries.
 * Each value is clamped to [0, 17].
 *
 * @param  mixed $raw     Raw value from request body
 * @param  int   $count   Expected number of ages (children count)
 * @return int[]
 */
function rs_child_ages(mixed $raw, int $count): array
{
    if ($count <= 0) {
        return [];
    }
    if (!is_array($raw)) {
        // Default all ages to 8 when not provided
        return array_fill(0, $count, 8);
    }
    $ages = [];
    foreach (array_slice($raw, 0, $count) as $age) {
        $n      = rs_int($age, 8);
        $ages[] = max(0, min(17, $n));
    }
    // Pad with 8 if fewer ages than children were sent
    while (count($ages) < $count) {
        $ages[] = 8;
    }
    return $ages;
}
