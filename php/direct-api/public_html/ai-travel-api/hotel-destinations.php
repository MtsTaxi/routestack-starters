<?php
/**
 * Hotel destination autocomplete
 *
 * POST /ai-travel-api/hotel-destinations.php
 * Body  : { "query": "London" }
 * Returns: { "success": true, "destinations": [{ "id": "...", "name": "..." }, ...] }
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

$query = rs_string($body['query'] ?? '');

if (strlen($query) < 2) {
    rs_error_exit(400, 'query must be at least 2 characters.');
}
if (strlen($query) > 200) {
    rs_error_exit(400, 'query is too long (max 200 characters).');
}

try {
    $json = rs_call_tool('hotel_search_destinations', ['query' => $query], 10);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

// Normalise: tool returns { result: [...] } or the array directly
$destinations = $json['result'] ?? [];
if (!is_array($destinations)) {
    $destinations = [];
}
if (empty($destinations) && array_is_list($json)) {
    $destinations = $json;
}

rs_json_exit(['success' => true, 'destinations' => $destinations]);
