<?php
/**
 * Hotel rate revalidation (price check before checkout)
 * POST /ai-travel-api/hotel-revalidate.php
 * Body (JSON): {
 *   "hotelId":          "...",
 *   "token":            "...",
 *   "correlationId":    "...",
 *   "recommendationId": "...",
 *   "roomId":           "...",    // optional
 *   "publishedRate":    189.00    // optional
 * }
 * Returns: { "success": true, "data": { ... } }
 *      OR: { "success": false, "message": "This offer has expired." } (HTTP 410)
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

$hotelId          = rs_string($body['hotelId'] ?? '');
$token            = rs_string($body['token'] ?? '');
$correlationId    = rs_string($body['correlationId'] ?? '');
$recommendationId = rs_string($body['recommendationId'] ?? '');
$roomId           = rs_string($body['roomId'] ?? '');
$publishedRate    = isset($body['publishedRate']) ? (float) $body['publishedRate'] : null;

if ($hotelId === '') rs_error_exit(400, 'hotelId is required.');
if ($token === '') rs_error_exit(400, 'token is required.');
if ($correlationId === '') rs_error_exit(400, 'correlationId is required.');
if ($recommendationId === '') rs_error_exit(400, 'recommendationId is required.');

$payload = [
    'hotelId'          => $hotelId,
    'token'            => $token,
    'correlationId'    => $correlationId,
    'recommendationId' => $recommendationId,
];
if ($roomId !== '') $payload['roomId'] = $roomId;
if ($publishedRate !== null) $payload['publishedRate'] = $publishedRate;

$result = rs_bridge_post('/hotel/revalidate', $payload, 20);

// Expired offer → 410 Gone
if (isset($result['message']) && str_contains($result['message'], 'expired')) {
    rs_json_exit($result, 410);
}

rs_json_exit($result, ($result['success'] ?? false) ? 200 : 502);
