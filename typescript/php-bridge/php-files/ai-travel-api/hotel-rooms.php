<?php
/**
 * Hotel rooms and rates
 * POST /ai-travel-api/hotel-rooms.php
 * Body (JSON): {
 *   "hotelId":       "...",
 *   "token":         "...",
 *   "correlationId": "...",
 *   "checkIn":       "2026-08-09",
 *   "checkOut":      "2026-08-12",
 *   "adults":        2,
 *   "children":      0,
 *   "rooms":         1,
 *   "currency":      "GBP"
 * }
 * Returns: { "success": true, "rooms": [...], "token": "...", "correlationId": "..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/bridge-client.php';

rs_bootstrap();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rs_error_exit(405, 'Method not allowed.');
}

rs_csrf_validate();

$raw  = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : [];
if (!is_array($body)) {
    rs_error_exit(400, 'Invalid JSON body.');
}

$hotelId       = rs_string($body['hotelId'] ?? '');
$token         = rs_string($body['token'] ?? '');
$correlationId = rs_string($body['correlationId'] ?? '');
$checkIn       = rs_date($body['checkIn'] ?? '');
$checkOut      = rs_date($body['checkOut'] ?? '');
$adults        = rs_int($body['adults'] ?? 1, 1);
$children      = rs_int($body['children'] ?? 0, 0);
$rooms         = rs_int($body['rooms'] ?? 1, 1);
$currency      = rs_currency($body['currency'] ?? 'GBP');

if ($hotelId === '') rs_error_exit(400, 'hotelId is required.');
if ($token === '') rs_error_exit(400, 'token is required.');
if ($correlationId === '') rs_error_exit(400, 'correlationId is required.');
if ($adults < 1 || $adults > 9) rs_error_exit(400, 'adults must be between 1 and 9.');
if ($children < 0 || $children > 9) rs_error_exit(400, 'children must be between 0 and 9.');
if ($rooms < 1 || $rooms > 9) rs_error_exit(400, 'rooms must be between 1 and 9.');

$payload = [
    'hotelId'       => $hotelId,
    'token'         => $token,
    'correlationId' => $correlationId,
    'adults'        => $adults,
    'children'      => $children,
    'rooms'         => $rooms,
    'currency'      => $currency,
];
if ($checkIn !== null)  $payload['checkIn']  = $checkIn;
if ($checkOut !== null) $payload['checkOut'] = $checkOut;

$result = rs_bridge_post('/hotel/rooms', $payload, 30);

rs_json_exit($result, ($result['success'] ?? false) ? 200 : 502);
