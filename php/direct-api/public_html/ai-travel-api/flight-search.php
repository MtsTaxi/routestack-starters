<?php
/**
 * Flight search
 *
 * POST /ai-travel-api/flight-search.php
 * Body:
 *   {
 *     "sessionId":       "...",      // from flight-session.php
 *     "originCode":      "LHR",
 *     "destinationCode": "JFK",
 *     "departureDate":   "2026-09-15",
 *     "returnDate":      "2026-09-22",  // optional — omit for one-way
 *     "adults":          2,
 *     "children":        0,
 *     "infants":         0,
 *     "cabinClass":      "Economy",     // Economy | Premium Economy | Business | First
 *     "currency":        "GBP"
 *   }
 * Returns:
 *   {
 *     "success":         true,
 *     "sessionId":       "...",
 *     "correlationId":   "...",
 *     "searchFilterObj": { ... },
 *     "flights": [{ fareSourceCode, stops, ourprice, currency, flights: [...legs...] }, ...]
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

$sessionId       = rs_string($body['sessionId']       ?? '');
$originCode      = strtoupper(rs_string($body['originCode']      ?? ''));
$destinationCode = strtoupper(rs_string($body['destinationCode'] ?? ''));
$departureDate   = rs_date($body['departureDate'] ?? '');
$returnDate      = rs_date($body['returnDate']    ?? '');
$adults          = rs_int($body['adults']    ?? 1, 1);
$children        = rs_int($body['children']  ?? 0, 0);
$infants         = rs_int($body['infants']   ?? 0, 0);
$cabinClass      = rs_string($body['cabinClass'] ?? 'Economy');
$currency        = rs_currency($body['currency'] ?? 'GBP');

if ($sessionId === '')                rs_error_exit(400, 'sessionId is required.');
if (!preg_match('/^[A-Z]{3}$/', $originCode))
                                      rs_error_exit(400, 'originCode must be a 3-letter IATA code.');
if (!preg_match('/^[A-Z]{3}$/', $destinationCode))
                                      rs_error_exit(400, 'destinationCode must be a 3-letter IATA code.');
if ($departureDate === null)          rs_error_exit(400, 'departureDate must be YYYY-MM-DD.');
if ($adults < 1 || $adults > 9)       rs_error_exit(400, 'adults must be 1–9.');
if ($children < 0 || $children > 9)   rs_error_exit(400, 'children must be 0–9.');
if ($infants < 0 || $infants > 9)     rs_error_exit(400, 'infants must be 0–9.');

$allowedCabin = ['Economy', 'Premium Economy', 'Business', 'First'];
if (!in_array($cabinClass, $allowedCabin, true)) {
    $cabinClass = 'Economy';
}

$toolArgs = [
    'sessionId'       => $sessionId,
    'originCode'      => $originCode,
    'destinationCode' => $destinationCode,
    'departureDate'   => $departureDate,
    'adults'          => $adults,
    'children'        => $children,
    'infants'         => $infants,
    'cabinClass'      => $cabinClass,
    'currency'        => $currency,
];
if ($returnDate !== null) {
    $toolArgs['returnDate'] = $returnDate;
}

try {
    $json = rs_call_tool('flight_search', $toolArgs, 30);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

$flights       = $json['result']['flights']    ?? [];
$retSessionId  = $json['sessionId']            ?? $sessionId;
$correlationId = $json['correlationId']        ?? null;
$searchFilter  = $json['searchFilterObj']      ?? null;

if (!is_array($flights) || empty($flights)) {
    rs_error_exit(422, 'No flights found for the selected route and dates.');
}

rs_json_exit([
    'success'         => true,
    'sessionId'       => $retSessionId,
    'correlationId'   => $correlationId,
    'searchFilterObj' => $searchFilter,
    'flights'         => array_slice($flights, 0, 10),
]);
