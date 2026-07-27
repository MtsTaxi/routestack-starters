<?php
/**
 * Hotel availability search
 *
 * POST /ai-travel-api/hotel-search.php
 * Body:
 *   {
 *     "destinationId": "...",
 *     "checkIn":       "2026-09-01",
 *     "checkOut":      "2026-09-05",
 *     "adults":        2,
 *     "children":      1,
 *     "childAges":     [8],       // one age per child (0–17); padded to 8 if omitted
 *     "rooms":         1,
 *     "currency":      "GBP",
 *     "nationality":   "GB"
 *   }
 * Returns:
 *   {
 *     "success":       true,
 *     "token":         "...",
 *     "correlationId": "...",
 *     "hotels":        [{ id, name, starRating, ourprice, publishedRate,
 *                         currency, saving, distance, heroImage, address }, ...]
 *   }
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

// Validate and sanitise inputs
$destinationId = rs_string($body['destinationId'] ?? '');
$checkIn       = rs_date($body['checkIn']   ?? $body['checkin']  ?? '');
$checkOut      = rs_date($body['checkOut']  ?? $body['checkout'] ?? '');
$adults        = rs_int($body['adults']          ?? 1, 1);
$children      = rs_int($body['children']        ?? 0, 0);
$childAges     = rs_child_ages($body['childAges'] ?? null, $children);
$rooms         = rs_int($body['rooms']           ?? 1, 1);
$currency      = rs_currency($body['currency']   ?? 'GBP');
$nationality   = rs_string($body['nationality']  ?? 'GB');

if ($destinationId === '') {
    rs_error_exit(400, 'destinationId is required.');
}
if ($checkIn === null) {
    rs_error_exit(400, 'checkIn must be a valid date (YYYY-MM-DD).');
}
if ($checkOut === null) {
    rs_error_exit(400, 'checkOut must be a valid date (YYYY-MM-DD).');
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

// Build tool arguments — include childAges only when children > 0
$toolArgs = [
    'destinationId' => $destinationId,
    'checkIn'       => $checkIn,
    'checkOut'      => $checkOut,
    'adults'        => $adults,
    'children'      => $children,
    'rooms'         => $rooms,
    'currency'      => $currency,
    'nationality'   => $nationality,
];
if ($children > 0) {
    $toolArgs['childAges'] = $childAges;
}

try {
    $json = rs_call_tool('hotel_search', $toolArgs, 30);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

// The tool returns { result: { token, correlationId, result: [...hotels...] } }
$data          = $json['result'] ?? [];
$hotels        = $data['result'] ?? [];
$token         = $data['token']         ?? null;
$correlationId = $data['correlationId'] ?? null;

if (!is_array($hotels) || empty($hotels)) {
    rs_error_exit(422, 'No hotels found for the selected destination and dates.');
}

// Normalise hotel objects
$normalised = array_map(static function (array $h) use ($currency): array {
    return [
        'id'           => $h['id']           ?? null,
        'name'         => $h['name']         ?? '',
        'starRating'   => $h['starRating']   ?? 0,
        'ourprice'     => $h['ourprice']     ?? null,
        'publishedRate'=> $h['publishedRate'] ?? null,
        'currency'     => $h['currency']     ?? $currency,
        'saving'       => $h['saving']       ?? null,
        'distance'     => $h['distance']     ?? null,
        'heroImage'    => $h['heroImage']    ?? null,
        'address'      => $h['address']      ?? null,
    ];
}, (array)$hotels);

rs_json_exit([
    'success'       => true,
    'token'         => $token,
    'correlationId' => $correlationId,
    'hotels'        => $normalised,
]);
