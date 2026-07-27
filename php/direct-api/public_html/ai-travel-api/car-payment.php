<?php
/**
 * Car hire payment URL
 *
 * POST /ai-travel-api/car-payment.php
 * Body  : { "vehicleId": "...", ... }   — passthrough to RouteStack checkout tool
 * Returns: { "success": true, "checkoutUrl": "https://..." }
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
if (!is_array($body) || empty($body)) {
    rs_error_exit(400, 'Invalid or empty JSON body.');
}

$tool = _rs_find_car_tool('checkout');
if ($tool === null) {
    rs_error_exit(501, 'Car hire checkout is not available on your RouteStack plan.');
}

try {
    $json = rs_call_tool($tool, $body, 20);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

$url = $json['url']               ??
       $json['checkoutUrl']        ??
       ($json['result']['url']         ?? null) ??
       ($json['result']['checkoutUrl'] ?? null);

if (!is_string($url) || $url === '') {
    rs_error_exit(502, 'No checkout URL returned from RouteStack.');
}

rs_json_exit(['success' => true, 'checkoutUrl' => $url]);

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
