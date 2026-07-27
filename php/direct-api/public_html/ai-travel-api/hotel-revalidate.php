<?php
/**
 * Hotel rate revalidation — must be called before generating a payment URL
 *
 * POST /ai-travel-api/hotel-revalidate.php
 * Body:
 *   {
 *     "hotelId":          "...",
 *     "token":            "...",   // from hotel-search.php (or hotel-details.php)
 *     "correlationId":    "...",
 *     "recommendationId": "...",   // from hotel-details.php room
 *     "roomId":           "...",   // optional
 *     "publishedRate":    189.00   // optional — for price-change detection
 *   }
 * Returns on success:
 *   { "success": true, "data": { ... revalidated rate object ... } }
 * Returns when rate expired:
 *   HTTP 410 { "success": false, "message": "This offer has expired. Please search again." }
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

$hotelId          = rs_string($body['hotelId']          ?? '');
$token            = rs_string($body['token']            ?? '');
$correlationId    = rs_string($body['correlationId']    ?? '');
$recommendationId = rs_string($body['recommendationId'] ?? '');
$roomId           = rs_string($body['roomId']           ?? '');
$publishedRate    = isset($body['publishedRate']) ? (float)$body['publishedRate'] : null;

if ($hotelId === '')          rs_error_exit(400, 'hotelId is required.');
if ($token === '')            rs_error_exit(400, 'token is required.');
if ($correlationId === '')    rs_error_exit(400, 'correlationId is required.');
if ($recommendationId === '') rs_error_exit(400, 'recommendationId is required.');

$toolArgs = [
    'hotelId'          => $hotelId,
    'token'            => $token,
    'correlationId'    => $correlationId,
    'recommendationId' => $recommendationId,
];
if ($roomId !== '')           $toolArgs['roomId']        = $roomId;
if ($publishedRate !== null)  $toolArgs['publishedRate'] = $publishedRate;

try {
    $json = rs_call_tool('hotel_revalidate_rate', $toolArgs, 20);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

// The tool returns the rate object, possibly nested under "result"
$data = $json['result'] ?? $json;

// Detect expired offer
if (
    ($data['expired'] ?? false) === true ||
    ($json['expired'] ?? false) === true
) {
    rs_error_exit(410, 'This offer has expired. Please search again.');
}

rs_json_exit(['success' => true, 'data' => $data]);
