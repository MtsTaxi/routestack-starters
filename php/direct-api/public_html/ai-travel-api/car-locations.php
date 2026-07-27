<?php
/**
 * Car hire location autocomplete
 *
 * POST /ai-travel-api/car-locations.php
 * Body  : { "query": "Manchester" }
 * Returns: { "success": true, "locations": [{ "id": "...", "name": "..." }, ...] }
 *
 * Tool name is discovered at runtime from the RouteStack MCP tool list.
 * The first tool whose name contains both "car" and "location" is used.
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
if (strlen($query) < 2) rs_error_exit(400, 'query must be at least 2 characters.');

// Discover car location tool
$tool = _rs_find_car_tool('location');
if ($tool === null) {
    rs_error_exit(501, 'Car hire location search is not available on your RouteStack plan.');
}

try {
    $json = rs_call_tool($tool, ['query' => $query], 10);
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

// ---------------------------------------------------------------------------
// Internal: discover a car hire tool by intent
// ---------------------------------------------------------------------------

function _rs_find_car_tool(string $intent): ?string
{
    try {
        $tools = rs_list_tools();
    } catch (RuntimeException) {
        return null;
    }

    $carTools = array_filter(
        array_column($tools, 'name'),
        static fn(string $n) => str_contains(strtolower($n), 'car')
    );

    $carTools = array_values($carTools);

    return match ($intent) {
        'location' => _rs_first_match($carTools, 'location'),
        'search'   => _rs_first_match($carTools, 'search')  ?? ($carTools[0] ?? null),
        'checkout' => _rs_first_match($carTools, 'checkout') ?? _rs_first_match($carTools, 'payment'),
        default    => null,
    };
}

function _rs_first_match(array $names, string $keyword): ?string
{
    foreach ($names as $n) {
        if (str_contains(strtolower($n), $keyword)) {
            return $n;
        }
    }
    return null;
}
