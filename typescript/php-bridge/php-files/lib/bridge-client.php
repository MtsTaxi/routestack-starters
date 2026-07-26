<?php
/**
 * Shared helpers for PHP proxies that call the RouteStack Node.js bridge.
 *
 * Usage in each endpoint:
 *   require_once __DIR__ . '/lib/bridge-client.php';
 *   rs_bootstrap();                // starts session, loads config
 *   rs_csrf_validate();            // verifies CSRF token (POST only)
 *   $data = rs_bridge_post('/hotel/search', $params);
 *   rs_json_exit($data);
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap — call once at the top of every endpoint file
// ---------------------------------------------------------------------------

function rs_bootstrap(): void
{
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

    // Load private config (above public_html — never web-accessible)
    $configPath = dirname($_SERVER['DOCUMENT_ROOT'])
        . '/private-config/routestack.php';

    if (!is_file($configPath)) {
        rs_error_exit(500, 'Bridge configuration file not found. '
            . 'Copy routestack.php.example to ' . $configPath);
    }

    require_once $configPath;

    if (!defined('BRIDGE_SECRET') || BRIDGE_SECRET === 'replace-with-the-same-value-as-in-dotenv') {
        rs_error_exit(500, 'BRIDGE_SECRET is not configured.');
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
    return $_SESSION['_rs_csrf'];
}

function rs_csrf_validate(): void
{
    $token    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    $expected = $_SESSION['_rs_csrf'] ?? '';

    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        rs_error_exit(403, 'CSRF token invalid or missing.');
    }
}

// ---------------------------------------------------------------------------
// cURL proxy to the Node.js bridge
// ---------------------------------------------------------------------------

/**
 * @param string               $path    e.g. '/hotel/search'
 * @param array<string, mixed> $body    JSON-serialisable payload
 * @param int                  $timeout Overall timeout in seconds (default 25)
 * @return array<string, mixed>
 */
function rs_bridge_post(string $path, array $body, int $timeout = 25): array
{
    $url = 'http://' . BRIDGE_HOST . ':' . BRIDGE_PORT . $path;

    $payload = json_encode($body);
    if ($payload === false) {
        return ['success' => false, 'message' => 'Failed to encode request payload.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . BRIDGE_SECRET,
            'Content-Length: ' . strlen($payload),
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FAILONERROR    => false,
        // Never disable SSL for external calls; internal loopback needs no cert
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0) {
        if ($errno === CURLE_OPERATION_TIMEOUTED) {
            return ['success' => false, 'message' => 'The bridge request timed out. Please try again.'];
        }
        return ['success' => false, 'message' => 'Bridge connection error: ' . $error];
    }

    if ($response === false || $response === '') {
        return ['success' => false, 'message' => 'Empty response from bridge.'];
    }

    $json = json_decode((string) $response, true);
    if (!is_array($json)) {
        return ['success' => false, 'message' => 'Invalid JSON from bridge.'];
    }

    // Propagate 4xx/5xx HTTP status from bridge if not already a failure
    if ($httpCode >= 400 && isset($json['success']) && $json['success'] !== false) {
        $json['success'] = false;
    }

    return $json;
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
    return filter_var($v, FILTER_VALIDATE_INT) !== false
        ? (int) $v
        : $default;
}

function rs_date(mixed $v): ?string
{
    if (!is_string($v)) return null;
    $d = \DateTime::createFromFormat('Y-m-d', trim($v));
    return ($d && $d->format('Y-m-d') === trim($v)) ? trim($v) : null;
}

/**
 * Allowed ISO 4217 currencies (extend as needed).
 */
function rs_currency(mixed $v): string
{
    $allowed = ['GBP', 'USD', 'EUR', 'AUD', 'CAD', 'CHF', 'SEK', 'NOK', 'DKK', 'JPY', 'INR', 'AED', 'SGD', 'HKD', 'NZD'];
    $clean   = strtoupper(trim(is_string($v) ? $v : ''));
    return in_array($clean, $allowed, true) ? $clean : 'GBP';
}
