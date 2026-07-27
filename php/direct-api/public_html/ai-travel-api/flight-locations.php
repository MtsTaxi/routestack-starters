<?php
/**
 * Flight airport / location autocomplete
 *
 * POST /ai-travel-api/flight-locations.php
 * Body  : { "sessionId": "...", "query": "London" }
 * Returns: { "success": true, "locations": [{ "code": "LHR", "name": "..." }, ...] }
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

$sessionId = rs_string($body['sessionId'] ?? '');
$query     = rs_string($body['query']     ?? '');

if ($sessionId === '') rs_error_exit(400, 'sessionId is required.');
if (strlen($query) < 2) rs_error_exit(400, 'query must be at least 2 characters.');

try {
    $json = rs_call_tool('flight_locations', [
        'sessionId' => $sessionId,
        'query'     => $query,
    ], 10);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

$locations = $json['result'] ?? [];
if (!is_array($locations)) {
    $locations = [];
}
if (empty($locations) && array_is_list($json)) {
    $locations = $json;
}

rs_json_exit(['success' => true, 'locations' => $locations]);
