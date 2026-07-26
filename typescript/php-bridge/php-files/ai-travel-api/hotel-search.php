<?php
/**
 * Hotel availability search
 * POST /ai-travel-api/hotel-search.php
 * Body (JSON): {
 *   "destinationId": "...",
 *   "checkIn":       "2026-08-09",
 *   "checkOut":      "2026-08-12",
 *   "adults":        2,
 *   "children":      0,
 *   "rooms":         1,
 *   "currency":      "GBP",
 *   "nationality":   "GB"
 * }
 * Returns: { "success": true, "hotels": [...], "token": "...", "correlationId": "..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/bridge-client.php';

rs_bootstrap();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rs_error_exit(405, 'Method not allowed.');
}

// CSRF validation
rs_csrf_validate();

// Parse JSON body
$raw  = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : [];
if (!is_array($body)) {
    rs_error_exit(400, 'Invalid JSON body.');
}

// Validate and sanitise inputs
$destinationId = rs_string($body['destinationId'] ?? '');
$checkIn       = rs_date($body['checkIn'] ?? '');
$checkOut      = rs_date($body['checkOut'] ?? '');
$adults        = rs_int($body['adults'] ?? 1, 1);
$children      = rs_int($body['children'] ?? 0, 0);
$rooms         = rs_int($body['rooms'] ?? 1, 1);
$currency      = rs_currency($body['currency'] ?? 'GBP');
$nationality   = rs_string($body['nationality'] ?? 'GB');

if ($destinationId === '') {
    rs_error_exit(400, 'destinationId is required.');
}
if ($checkIn === null) {
    rs_error_exit(400, 'checkIn must be a valid date in YYYY-MM-DD format.');
}
if ($checkOut === null) {
    rs_error_exit(400, 'checkOut must be a valid date in YYYY-MM-DD format.');
}
if (strtotime($checkIn) >= strtotime($checkOut)) {
    rs_error_exit(400, 'checkOut must be after checkIn.');
}
if (strtotime($checkIn) < strtotime('today')) {
    rs_error_exit(400, 'checkIn cannot be in the past.');
}
if ($adults < 1 || $adults > 9) {
    rs_error_exit(400, 'adults must be between 1 and 9.');
}
if ($children < 0 || $children > 9) {
    rs_error_exit(400, 'children must be between 0 and 9.');
}
if ($rooms < 1 || $rooms > 9) {
    rs_error_exit(400, 'rooms must be between 1 and 9.');
}
if (!preg_match('/^[A-Z]{2}$/', $nationality)) {
    $nationality = 'GB';
}

// Forward to bridge (generous timeout — hotel search can be slow)
$result = rs_bridge_post('/hotel/search', [
    'destinationId' => $destinationId,
    'checkIn'       => $checkIn,
    'checkOut'      => $checkOut,
    'adults'        => $adults,
    'children'      => $children,
    'rooms'         => $rooms,
    'currency'      => $currency,
    'nationality'   => $nationality,
], 30);

$status = ($result['success'] ?? false) ? 200 : (
    str_contains($result['message'] ?? '', 'No hotel') ? 422 : 502
);

rs_json_exit($result, $status);
