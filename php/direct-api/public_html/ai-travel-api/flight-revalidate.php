<?php
/**
 * Flight fare revalidation — call before generating a payment URL
 *
 * POST /ai-travel-api/flight-revalidate.php
 * Body:
 *   {
 *     "sessionId":       "...",
 *     "correlationId":   "...",
 *     "fareSourceCode":  "...",   // from flight-search.php result
 *     "searchFilterObj": { ... }  // from flight-search.php result
 *   }
 * Returns on success:
 *   { "success": true, "data": { ... revalidated fare ... } }
 * Returns when fare expired:
 *   HTTP 410 { "success": false, "message": "This fare has expired. Please search again." }
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

$sessionId      = rs_string($body['sessionId']      ?? '');
$correlationId  = rs_string($body['correlationId']  ?? '');
$fareSourceCode = rs_string($body['fareSourceCode'] ?? '');
$searchFilter   = $body['searchFilterObj'] ?? null;

if ($sessionId === '')      rs_error_exit(400, 'sessionId is required.');
if ($correlationId === '')  rs_error_exit(400, 'correlationId is required.');
if ($fareSourceCode === '') rs_error_exit(400, 'fareSourceCode is required.');
if (!is_array($searchFilter)) rs_error_exit(400, 'searchFilterObj is required and must be an object.');

try {
    $json = rs_call_tool('flight_revalidate', [
        'sessionId'      => $sessionId,
        'correlationId'  => $correlationId,
        'fareSourceCode' => $fareSourceCode,
        'searchFilterObj'=> $searchFilter,
    ], 20);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

$data = $json['result'] ?? $json;

// Detect expired fare
if (
    ($data['expired'] ?? false) === true ||
    ($json['expired'] ?? false) === true
) {
    rs_error_exit(410, 'This fare has expired. Please search again.');
}

rs_json_exit(['success' => true, 'data' => $data]);
