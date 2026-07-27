<?php
/**
 * Flight payment URL
 *
 * POST /ai-travel-api/flight-payment.php
 * Body:
 *   {
 *     "sessionId":      "...",
 *     "fareSourceCode": "..."   // from flight-search.php result
 *   }
 * Returns:
 *   { "success": true, "checkoutUrl": "https://checkout.routestack.ai/..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/routestack.php';

rs_bootstrap();
rs_rate_limit();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rs_error_exit(405, 'Method not allowed.');
}

rs_csrf_validate();

$raw  = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) {
    rs_error_exit(400, 'Invalid JSON body.');
}

$sessionId      = rs_string($body['sessionId']      ?? '');
$fareSourceCode = rs_string($body['fareSourceCode'] ?? '');

if ($sessionId === '')      rs_error_exit(400, 'sessionId is required.');
if ($fareSourceCode === '') rs_error_exit(400, 'fareSourceCode is required.');

try {
    $json = rs_call_tool('flight_get_checkout_url', [
        'sessionId'      => $sessionId,
        'fareSourceCode' => $fareSourceCode,
    ], 20);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

$url = $json['url']               ??
       $json['checkoutUrl']        ??
       ($json['result']['url']         ?? null) ??
       ($json['result']['checkoutUrl'] ?? null);

if (!is_string($url) || $url === '') {
    rs_error_exit(502, 'No checkout URL returned from RouteStack.');
}

rs_json_exit(['success' => true, 'checkoutUrl' => $url]);
