<?php
/**
 * Car hire search
 *
 * POST /ai-travel-api/car-search.php
 * Body:
 *   {
 *     "pickupLocationId":  "...",   // from car-locations.php
 *     "pickupDate":        "2026-09-01",
 *     "dropoffDate":       "2026-09-05",
 *     "dropoffLocationId": "...",   // optional — same as pickup if omitted
 *     "driverAge":         30,
 *     "currency":          "GBP"
 *   }
 * Returns:
 *   { "success": true, "cars": [{ id, name, category, price, currency, ... }, ...] }
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

$pickupLocationId  = rs_string($body['pickupLocationId']  ?? '');
$dropoffLocationId = rs_string($body['dropoffLocationId'] ?? '');
$pickupDate        = rs_date($body['pickupDate']   ?? '');
$dropoffDate       = rs_date($body['dropoffDate']  ?? '');
$driverAge         = rs_int($body['driverAge'] ?? 30, 30);
$currency          = rs_currency($body['currency'] ?? 'GBP');

if ($pickupLocationId === '') rs_error_exit(400, 'pickupLocationId is required.');
if ($pickupDate === null)     rs_error_exit(400, 'pickupDate must be YYYY-MM-DD.');
if ($dropoffDate === null)    rs_error_exit(400, 'dropoffDate must be YYYY-MM-DD.');
if (strtotime($pickupDate) >= strtotime($dropoffDate)) {
    rs_error_exit(400, 'dropoffDate must be after pickupDate.');
}
if ($driverAge < 18 || $driverAge > 99) rs_error_exit(400, 'driverAge must be 18–99.');

$tool = _rs_find_car_tool('search');
if ($tool === null) {
    rs_error_exit(501, 'Car hire search is not available on your RouteStack plan.');
}

$toolArgs = [
    'pickupLocationId' => $pickupLocationId,
    'pickupDate'       => $pickupDate,
    'dropoffDate'      => $dropoffDate,
    'driverAge'        => $driverAge,
    'currency'         => $currency,
];
if ($dropoffLocationId !== '') {
    $toolArgs['dropoffLocationId'] = $dropoffLocationId;
}

try {
    $json = rs_call_tool($tool, $toolArgs, 25);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

$cars = $json['result'] ?? [];
if (!is_array($cars)) {
    $cars = [];
}
if (empty($cars) && array_is_list($json)) {
    $cars = $json;
}

if (empty($cars)) {
    rs_error_exit(422, 'No cars available for the selected location and dates.');
}

rs_json_exit(['success' => true, 'cars' => $cars]);

// ---------------------------------------------------------------------------
function _rs_find_car_tool(string $intent): ?string
{
    try {
        $tools = rs_list_tools();
    } catch (RuntimeException) {
        return null;
    }
    $carTools = array_values(array_filter(
        array_column($tools, 'name'),
        static fn(string $n) => str_contains(strtolower($n), 'car')
    ));
    return match ($intent) {
        'search'   => _rs_first_match($carTools, 'search') ?? ($carTools[0] ?? null),
        'checkout' => _rs_first_match($carTools, 'checkout') ?? _rs_first_match($carTools, 'payment'),
        default    => null,
    };
}
function _rs_first_match(array $names, string $keyword): ?string
{
    foreach ($names as $n) {
        if (str_contains(strtolower($n), $keyword)) return $n;
    }
    return null;
}
