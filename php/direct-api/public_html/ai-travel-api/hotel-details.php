<?php
/**
 * Hotel rooms and rates
 *
 * POST /ai-travel-api/hotel-details.php
 * Body:
 *   {
 *     "hotelId":       "...",
 *     "token":         "...",        // from hotel-search.php
 *     "correlationId": "...",        // from hotel-search.php
 *     "checkIn":       "2026-09-01", // optional — re-send search dates
 *     "checkOut":      "2026-09-05", // optional
 *     "adults":        2,
 *     "children":      1,
 *     "childAges":     [8],
 *     "rooms":         1,
 *     "currency":      "GBP"
 *   }
 * Returns:
 *   {
 *     "success":       true,
 *     "token":         "...",
 *     "correlationId": "...",
 *     "hotelId":       "...",
 *     "rooms": [{ id, name, description, recommendationId, rateid,
 *                 ourprice, publishedRate, refundable, boardBasis }, ...]
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

$hotelId       = rs_string($body['hotelId']       ?? '');
$token         = rs_string($body['token']         ?? '');
$correlationId = rs_string($body['correlationId'] ?? '');
$checkIn       = rs_date($body['checkIn']         ?? '');
$checkOut      = rs_date($body['checkOut']        ?? '');
$adults        = rs_int($body['adults']           ?? 1, 1);
$children      = rs_int($body['children']         ?? 0, 0);
$childAges     = rs_child_ages($body['childAges'] ?? null, $children);
$rooms         = rs_int($body['rooms']            ?? 1, 1);
$currency      = rs_currency($body['currency']    ?? 'GBP');

if ($hotelId === '')       rs_error_exit(400, 'hotelId is required.');
if ($token === '')         rs_error_exit(400, 'token is required.');
if ($correlationId === '') rs_error_exit(400, 'correlationId is required.');
if ($adults < 1 || $adults > 9)     rs_error_exit(400, 'adults must be 1–9.');
if ($children < 0 || $children > 9) rs_error_exit(400, 'children must be 0–9.');
if ($rooms < 1 || $rooms > 9)       rs_error_exit(400, 'rooms must be 1–9.');

$toolArgs = [
    'hotelId'       => $hotelId,
    'token'         => $token,
    'correlationId' => $correlationId,
    'adults'        => $adults,
    'children'      => $children,
    'rooms'         => $rooms,
    'currency'      => $currency,
];
if ($checkIn !== null)  $toolArgs['checkIn']  = $checkIn;
if ($checkOut !== null) $toolArgs['checkOut'] = $checkOut;
if ($children > 0)      $toolArgs['childAges'] = $childAges;

try {
    $json = rs_call_tool('hotel_get_rooms_and_rates', $toolArgs, 30);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

// The tool returns { result: { id, token, correlationId, groups: [{rooms:[...]}] } }
$data          = $json['result'] ?? [];
$groups        = $data['groups']        ?? [];
$retToken      = $data['token']         ?? $token;
$retCorrId     = $data['correlationId'] ?? $correlationId;
$retHotelId    = $data['id']            ?? $hotelId;

// Flatten rooms from all groups
$flatRooms = [];
foreach ((array)$groups as $group) {
    foreach ($group['rooms'] ?? [] as $r) {
        $flatRooms[] = [
            'id'               => $r['id']               ?? null,
            'name'             => $r['name']             ?? '',
            'description'      => $r['description']      ?? null,
            'recommendationId' => $r['recommendationId'] ?? null,
            'rateid'           => $r['rateid']           ?? null,
            'ourprice'         => $r['ourprice']         ?? null,
            'publishedRate'    => $r['publishedRate']    ?? null,
            'refundable'       => (bool)($r['refundable'] ?? false),
            'boardBasis'       => $r['boardBasis']       ?? null,
        ];
    }
}

if (empty($flatRooms)) {
    rs_error_exit(422, 'No rooms available for the selected dates.');
}

rs_json_exit([
    'success'       => true,
    'token'         => $retToken,
    'correlationId' => $retCorrId,
    'hotelId'       => $retHotelId,
    'rooms'         => $flatRooms,
]);
