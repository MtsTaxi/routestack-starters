<?php
/**
 * Hotel checkout URL generation
 * POST /ai-travel-api/hotel-checkout.php
 * Body (JSON): {
 *   "token":            "...",
 *   "correlationId":    "...",
 *   "hotelId":          "...",
 *   "recommendationId": "...",
 *   "roomId":           "...",    // optional
 *   "publishedRate":    189.00    // optional
 * }
 * Returns: { "success": true, "checkoutUrl": "https://..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bridge-client.php';

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

$token            = rs_string($body['token'] ?? '');
$correlationId    = rs_string($body['correlationId'] ?? '');
$hotelId          = rs_string($body['hotelId'] ?? '');
$recommendationId = rs_string($body['recommendationId'] ?? '');
$roomId           = rs_string($body['roomId'] ?? '');
$publishedRate    = isset($body['publishedRate']) ? (float) $body['publishedRate'] : null;

if ($token === '') rs_error_exit(400, 'token is required.');
if ($correlationId === '') rs_error_exit(400, 'correlationId is required.');
if ($hotelId === '') rs_error_exit(400, 'hotelId is required.');
if ($recommendationId === '') rs_error_exit(400, 'recommendationId is required.');

$payload = [
    'token'            => $token,
    'correlationId'    => $correlationId,
    'hotelId'          => $hotelId,
    'recommendationId' => $recommendationId,
];
if ($roomId !== '') $payload['roomId'] = $roomId;
if ($publishedRate !== null) $payload['publishedRate'] = $publishedRate;

$result = rs_bridge_post('/hotel/checkout', $payload, 20);

rs_json_exit($result, ($result['success'] ?? false) ? 200 : 502);
