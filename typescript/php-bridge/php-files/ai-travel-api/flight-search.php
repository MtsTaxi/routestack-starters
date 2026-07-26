<?php
/**
 * Flight search (session → locations → search in one call)
 * POST /ai-travel-api/flight-search.php
 *
 * Step 1 — Create session:
 *   Body: { "action": "session" }
 *   Returns: { "success": true, "sessionId": "..." }
 *
 * Step 2 — Location lookup:
 *   Body: { "action": "locations", "sessionId": "...", "query": "London" }
 *   Returns: { "success": true, "locations": [...] }
 *
 * Step 3 — Search flights:
 *   Body: {
 *     "action":          "search",
 *     "sessionId":       "...",
 *     "originCode":      "LHR",
 *     "destinationCode": "JFK",
 *     "departureDate":   "2026-09-15",
 *     "returnDate":      "2026-09-22",   // optional for one-way
 *     "adults":          2,
 *     "children":        0,
 *     "infants":         0,
 *     "cabinClass":      "Economy",
 *     "currency":        "GBP"
 *   }
 *   Returns: { "success": true, "flights": [...], "correlationId": "...", ... }
 *
 * Step 4 — Revalidate:
 *   Body: { "action": "revalidate", "sessionId": "...", "correlationId": "...",
 *           "fareSourceCode": "...", "searchFilterObj": {...} }
 *
 * Step 5 — Checkout URL:
 *   Body: { "action": "checkout", "sessionId": "...", "fareSourceCode": "..." }
 *   Returns: { "success": true, "checkoutUrl": "https://..." }
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

$action = rs_string($body['action'] ?? '');

switch ($action) {
    // ------------------------------------------------------------------
    case 'session':
        $result = rs_bridge_post('/flight/session', [], 10);
        rs_json_exit($result, ($result['success'] ?? false) ? 200 : 502);

    // ------------------------------------------------------------------
    case 'locations':
        $sessionId = rs_string($body['sessionId'] ?? '');
        $query     = rs_string($body['query'] ?? '');
        if ($sessionId === '') rs_error_exit(400, 'sessionId is required.');
        if (strlen($query) < 2) rs_error_exit(400, 'query must be at least 2 characters.');
        $result = rs_bridge_post('/flight/locations', [
            'sessionId' => $sessionId,
            'query'     => $query,
        ], 10);
        rs_json_exit($result, ($result['success'] ?? false) ? 200 : 502);

    // ------------------------------------------------------------------
    case 'search':
        $sessionId       = rs_string($body['sessionId'] ?? '');
        $originCode      = strtoupper(rs_string($body['originCode'] ?? ''));
        $destinationCode = strtoupper(rs_string($body['destinationCode'] ?? ''));
        $departureDate   = rs_date($body['departureDate'] ?? '');
        $returnDate      = rs_date($body['returnDate'] ?? null);
        $adults          = rs_int($body['adults'] ?? 1, 1);
        $children        = rs_int($body['children'] ?? 0, 0);
        $infants         = rs_int($body['infants'] ?? 0, 0);
        $cabinClass      = rs_string($body['cabinClass'] ?? 'Economy');
        $currency        = rs_currency($body['currency'] ?? 'GBP');

        if ($sessionId === '') rs_error_exit(400, 'sessionId is required.');
        if ($originCode === '') rs_error_exit(400, 'originCode is required.');
        if ($destinationCode === '') rs_error_exit(400, 'destinationCode is required.');
        if ($departureDate === null) rs_error_exit(400, 'departureDate must be YYYY-MM-DD.');
        if ($adults < 1 || $adults > 9) rs_error_exit(400, 'adults must be 1–9.');

        $allowed = ['Economy', 'Premium Economy', 'Business', 'First'];
        if (!in_array($cabinClass, $allowed, true)) $cabinClass = 'Economy';

        $payload = [
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
        if ($returnDate !== null) $payload['returnDate'] = $returnDate;

        $result = rs_bridge_post('/flight/search', $payload, 30);
        $status = ($result['success'] ?? false) ? 200
            : (str_contains($result['message'] ?? '', 'No flight') ? 422 : 502);
        rs_json_exit($result, $status);

    // ------------------------------------------------------------------
    case 'revalidate':
        $sessionId      = rs_string($body['sessionId'] ?? '');
        $correlationId  = rs_string($body['correlationId'] ?? '');
        $fareSourceCode = rs_string($body['fareSourceCode'] ?? '');

        if ($sessionId === '') rs_error_exit(400, 'sessionId is required.');
        if ($correlationId === '') rs_error_exit(400, 'correlationId is required.');
        if ($fareSourceCode === '') rs_error_exit(400, 'fareSourceCode is required.');
        if (!isset($body['searchFilterObj']) || !is_array($body['searchFilterObj'])) {
            rs_error_exit(400, 'searchFilterObj is required.');
        }

        $result = rs_bridge_post('/flight/revalidate', [
            'sessionId'      => $sessionId,
            'correlationId'  => $correlationId,
            'fareSourceCode' => $fareSourceCode,
            'searchFilterObj'=> $body['searchFilterObj'],
        ], 20);

        $status = ($result['success'] ?? false) ? 200
            : (str_contains($result['message'] ?? '', 'expired') ? 410 : 502);
        rs_json_exit($result, $status);

    // ------------------------------------------------------------------
    case 'checkout':
        $sessionId      = rs_string($body['sessionId'] ?? '');
        $fareSourceCode = rs_string($body['fareSourceCode'] ?? '');
        if ($sessionId === '') rs_error_exit(400, 'sessionId is required.');
        if ($fareSourceCode === '') rs_error_exit(400, 'fareSourceCode is required.');
        $result = rs_bridge_post('/flight/checkout', [
            'sessionId'      => $sessionId,
            'fareSourceCode' => $fareSourceCode,
        ], 20);
        rs_json_exit($result, ($result['success'] ?? false) ? 200 : 502);

    // ------------------------------------------------------------------
    default:
        rs_error_exit(400, 'action must be one of: session, locations, search, revalidate, checkout.');
}
