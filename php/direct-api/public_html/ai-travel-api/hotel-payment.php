<?php
/**
 * Hotel checkout / payment URL
 *
 * POST /ai-travel-api/hotel-payment.php
 * Body:
 *   {
 *     "token":            "...",
 *     "correlationId":    "...",
 *     "hotelId":          "...",
 *     "recommendationId": "...",
 *     "roomId":           "...",   // optional
 *     "publishedRate":    189.00   // optional
 *   }
 * Returns:
 *   { "success": true, "checkoutUrl": "https://checkout.routestack.ai/..." }
 *
 * The client redirects to checkoutUrl to complete payment on RouteStack's
 * hosted checkout page.
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

$token            = rs_string($body['token']            ?? '');
$correlationId    = rs_string($body['correlationId']    ?? '');
$hotelId          = rs_string($body['hotelId']          ?? '');
$recommendationId = rs_string($body['recommendationId'] ?? '');
$roomId           = rs_string($body['roomId']           ?? '');
$publishedRate    = isset($body['publishedRate']) ? (float)$body['publishedRate'] : null;

if ($token === '')            rs_error_exit(400, 'token is required.');
if ($correlationId === '')    rs_error_exit(400, 'correlationId is required.');
if ($hotelId === '')          rs_error_exit(400, 'hotelId is required.');
if ($recommendationId === '') rs_error_exit(400, 'recommendationId is required.');

$toolArgs = [
    'token'            => $token,
    'correlationId'    => $correlationId,
    'hotelId'          => $hotelId,
    'recommendationId' => $recommendationId,
];
if ($roomId !== '')          $toolArgs['roomId']        = $roomId;
if ($publishedRate !== null) $toolArgs['publishedRate'] = $publishedRate;

try {
    $json = rs_call_tool('hotel_get_checkout_url', $toolArgs, 20);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

// The tool returns the checkout URL at various possible keys
$url = $json['url']               ??
       $json['checkoutUrl']        ??
       ($json['result']['url']         ?? null) ??
       ($json['result']['checkoutUrl'] ?? null);

if (!is_string($url) || $url === '') {
    rs_error_exit(502, 'No checkout URL returned from RouteStack.');
}

rs_json_exit(['success' => true, 'checkoutUrl' => $url]);
