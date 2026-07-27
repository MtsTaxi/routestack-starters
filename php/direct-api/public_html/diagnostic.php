<?php
/**
 * diagnostic.php — RouteStack integration health check
 *
 * SAFE: never exposes credentials or token values.
 * Shows: PHP version · extensions · private config exists · connectivity.
 *
 * Place at: public_html/diagnostic.php
 * Protect in production: add Basic Auth via .htaccess or remove the file.
 *
 * Usage: https://yourdomain.com/diagnostic.php
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// Block from being indexed
header('X-Robots-Tag: noindex, nofollow');

// --------------------------------------------------------------------------
// Helpers
// --------------------------------------------------------------------------

function row(string $label, bool $ok, string $detail = ''): string
{
    $icon   = $ok ? '✅' : '❌';
    $colour = $ok ? '#085' : '#c00';
    return sprintf(
        '<tr><td>%s %s</td><td style="color:%s">%s</td><td style="color:#555;font-size:13px">%s</td></tr>',
        $icon, htmlspecialchars($label, ENT_QUOTES), $colour, $ok ? 'OK' : 'FAIL',
        htmlspecialchars($detail, ENT_QUOTES)
    );
}

function rowInfo(string $label, string $value): string
{
    return sprintf(
        '<tr><td>ℹ️ %s</td><td colspan="2" style="color:#333">%s</td></tr>',
        htmlspecialchars($label, ENT_QUOTES),
        htmlspecialchars($value, ENT_QUOTES)
    );
}

// --------------------------------------------------------------------------
// Checks
// --------------------------------------------------------------------------

$rows = '';

// PHP version
$phpVer   = PHP_VERSION;
$phpOk    = version_compare($phpVer, '8.2.0', '>=');
$rows    .= rowInfo('PHP version', $phpVer);
$rows    .= row('PHP >= 8.2', $phpOk, $phpOk ? '' : 'Requires PHP 8.2 or later');

// Required extensions
foreach (['curl', 'json', 'openssl', 'hash', 'session'] as $ext) {
    $rows .= row("Extension: $ext", extension_loaded($ext));
}

// Optional extension (rate limiting)
$rows .= row('Extension: apcu (optional, for rate limiting)', extension_loaded('apcu'),
    extension_loaded('apcu') ? '' : 'Rate limiting silently disabled');

// Private config / client
$privDir     = dirname($_SERVER['DOCUMENT_ROOT']) . '/routestack_private';
$configFile  = $privDir . '/config.php';
$clientFile  = $privDir . '/client.php';

$privDirOk   = is_dir($privDir);
$configOk    = is_file($configFile);
$clientOk    = is_file($clientFile);

$rows .= row('Private directory exists', $privDirOk, $privDir);
$rows .= row('config.php exists',        $configOk,  $configOk  ? '(contents hidden)' : "Expected: $configFile");
$rows .= row('client.php exists',        $clientOk,  $clientOk  ? '(contents hidden)' : "Expected: $clientFile");

// Token-cache file location (if config loadable)
if ($configOk && $clientOk) {
    try {
        /** @noinspection PhpIncludeInspection */
        require_once $configFile;

        // Check token cache dir
        if (defined('RS_TOKEN_CACHE_FILE')) {
            $cacheDir = dirname(RS_TOKEN_CACHE_FILE);
            $cacheDirOk = is_dir($cacheDir) && is_writable($cacheDir);
            $rows .= row('Token cache directory writable', $cacheDirOk, $cacheDir);
        } else {
            $rows .= row('RS_TOKEN_CACHE_FILE defined', false, 'constant missing in config.php');
        }

        $baseUrl = defined('RS_BASE_URL') ? RS_BASE_URL : null;
        if ($baseUrl) {
            $rows .= rowInfo('RS_BASE_URL', $baseUrl);
        } else {
            $rows .= row('RS_BASE_URL defined', false);
        }

        $apiKeyDefined = defined('RS_API_KEY') && RS_API_KEY !== '' && RS_API_KEY !== 'your-api-key-here';
        $rows .= row('RS_API_KEY configured', $apiKeyDefined, $apiKeyDefined ? '(hidden)' : 'placeholder still set');

        $secretDefined = defined('RS_API_SECRET') && RS_API_SECRET !== '' && RS_API_SECRET !== 'your-api-secret-here';
        $rows .= row('RS_API_SECRET configured', $secretDefined, $secretDefined ? '(hidden)' : 'placeholder still set');

        // Connectivity test (no auth — just TCP)
        if ($baseUrl) {
            $host    = parse_url($baseUrl, PHP_URL_HOST);
            $port    = parse_url($baseUrl, PHP_URL_SCHEME) === 'https' ? 443 : 80;
            $timeout = 5;
            $fp = @fsockopen(($port === 443 ? 'ssl://' : '') . $host, $port, $errno, $errstr, $timeout);
            if ($fp) {
                fclose($fp);
                $rows .= row("TCP connectivity to $host:$port", true);
            } else {
                $rows .= row("TCP connectivity to $host:$port", false, "$errno $errstr");
            }
        }

        // Live HMAC auth test — only run when credentials look real
        if ($baseUrl && $apiKeyDefined && $secretDefined) {
            try {
                require_once $clientFile;

                $ts    = time();
                $nonce = bin2hex(random_bytes(16));
                $raw   = hash_hmac('sha256', RS_API_KEY . ':' . $ts . ':' . $nonce, RS_API_SECRET, true);
                $hmac  = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

                $authPayload = (string)json_encode([
                    'apiKey'    => RS_API_KEY,
                    'hmac'      => $hmac,
                    'timestamp' => $ts,
                    'nonce'     => $nonce,
                ]);

                $ch = curl_init($baseUrl . '/mcp/auth/partner-token');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $authPayload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($authPayload),
                    ],
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $authRes    = curl_exec($ch);
                $authCode   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $authCurlErr = curl_error($ch);
                curl_close($ch);

                if ($authRes === false) {
                    $rows .= row('HMAC auth endpoint reachable', false, 'cURL: ' . $authCurlErr);
                } elseif ($authCode === 200) {
                    $authData = json_decode((string)$authRes, true);
                    $hasToken = is_array($authData) && (
                        isset($authData['token']) || isset($authData['accessToken']) ||
                        isset($authData['partnerToken']) || isset($authData['jwt'])
                    );
                    $rows .= row('HMAC auth → HTTP 200', true, $hasToken ? 'JWT received ✓' : 'Warning: response missing token field');
                } else {
                    $snippet = substr((string)$authRes, 0, 300);
                    $rows .= row('HMAC auth → HTTP ' . $authCode, false, $snippet);
                }

                // Live MCP tools/list test (uses partner token if auth succeeded)
                if ($authCode === 200) {
                    try {
                        $token = rs_get_partner_token();
                        $listId = uniqid('diag_', true);
                        $listPayload = (string)json_encode([
                            'jsonrpc' => '2.0',
                            'id'      => $listId,
                            'method'  => 'tools/list',
                            'params'  => [],
                        ]);
                        $ch2 = curl_init($baseUrl . '/mcp');
                        curl_setopt_array($ch2, [
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => $listPayload,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/json',
                                'Authorization: Bearer ' . $token,
                                'Accept: application/json, text/event-stream',
                                'Content-Length: ' . strlen($listPayload),
                            ],
                            CURLOPT_CONNECTTIMEOUT => 5,
                            CURLOPT_TIMEOUT        => 15,
                            CURLOPT_SSL_VERIFYPEER => true,
                            CURLOPT_SSL_VERIFYHOST => 2,
                        ]);
                        $listRes  = curl_exec($ch2);
                        $listCode = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                        $listErr  = curl_error($ch2);
                        curl_close($ch2);

                        if ($listRes === false) {
                            $rows .= row('MCP tools/list reachable', false, 'cURL: ' . $listErr);
                        } elseif ($listCode === 200) {
                            // Parse JSON or SSE
                            $listBody = trim((string)$listRes);
                            $listData = json_decode($listBody, true);
                            if (!is_array($listData)) {
                                foreach (explode("\n", $listBody) as $line) {
                                    $line = trim($line);
                                    if (str_starts_with($line, 'data: ')) {
                                        $listData = json_decode(substr($line, 6), true);
                                        if (is_array($listData)) break;
                                    }
                                }
                            }
                            $toolCount = count($listData['result']['tools'] ?? []);
                            $rows .= row('MCP tools/list → HTTP 200', true, $toolCount . ' tool' . ($toolCount !== 1 ? 's' : '') . ' available');
                        } else {
                            $snippet = substr((string)$listRes, 0, 300);
                            $rows .= row('MCP tools/list → HTTP ' . $listCode, false, $snippet);
                        }
                    } catch (Throwable $te) {
                        $rows .= row('MCP tools/list', false, $te->getMessage());
                    }
                }

            } catch (Throwable $te) {
                $rows .= row('Live API test', false, $te->getMessage());
            }
        } else {
            $rows .= rowInfo('Live API test skipped', 'Credentials are placeholders — fill in config.php first');
        }

    } catch (Throwable $t) {
        $rows .= row('config.php loadable', false, $t->getMessage());
    }
} else {
    $rows .= rowInfo('Config check skipped', 'config.php or client.php not found above public_html');
}

// Session writable
$sessionOk = false;
$sessionDir = ini_get('session.save_path') ?: sys_get_temp_dir();
try {
    session_start();
    $_SESSION['_rs_diag_test'] = 1;
    session_write_close();
    $sessionOk = true;
} catch (Throwable) { /* pass */ }
$rows .= row('PHP sessions writable', $sessionOk, $sessionDir);

// AI Travel API dir accessible (not necessarily browsable)
$apiDir  = __DIR__ . '/ai-travel-api';
$apiOk   = is_dir($apiDir);
$rows   .= row('ai-travel-api/ directory exists', $apiOk, $apiDir);

// lib/routestack.php loadable
$libFile = $apiDir . '/lib/routestack.php';
$rows   .= row('ai-travel-api/lib/routestack.php exists', is_file($libFile));

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>RouteStack Diagnostic — I LOVE VOYAGE</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
         background: #f4f7fb; color: #222; padding: 30px 20px; }
  h1 { font-size: 22px; margin-bottom: 20px; }
  table { border-collapse: collapse; width: 100%; max-width: 860px; background: #fff;
          box-shadow: 0 2px 8px rgba(0,0,0,.1); border-radius: 8px; overflow: hidden; }
  th { background: #0070c0; color: #fff; padding: 10px 14px; text-align: left; font-size: 13px; }
  td { padding: 9px 14px; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: top; }
  tr:last-child td { border-bottom: none; }
  .notice { max-width: 860px; margin-top: 18px; padding: 12px 16px;
            background: #fff3cd; border-left: 4px solid #e6a817;
            border-radius: 4px; font-size: 13px; }
</style>
</head>
<body>
<h1>🔍 RouteStack Integration Diagnostic</h1>
<table>
  <tr>
    <th>Check</th>
    <th>Status</th>
    <th>Detail</th>
  </tr>
  <?= $rows ?>
</table>
<div class="notice">
  ⚠️ <strong>Security reminder:</strong> Remove or password-protect
  <code>diagnostic.php</code> before going live. It never exposes credentials,
  but it reveals your server configuration.
</div>
</body>
</html>
