<?php
/**
 * Car hire search
 * POST /ai-travel-api/car-search.php
 *
 * action = "locations"  → { query }
 * action = "search"     → { pickupLocationId, pickupDate, dropoffDate,
 *                           dropoffLocationId?, driverAge?, currency? }
 * action = "checkout"   → { vehicleId, ... }  (passthrough)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bridge-client.php';

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
    case 'locations':
        $query = rs_string($body['query'] ?? '');
        if (strlen($query) < 2) rs_error_exit(400, 'query must be at least 2 characters.');
        $result = rs_bridge_post('/car/locations', ['query' => $query], 10);
        rs_json_exit($result, ($result['success'] ?? false) ? 200 : 502);

    // ------------------------------------------------------------------
    case 'search':
        $pickupLocationId  = rs_string($body['pickupLocationId'] ?? '');
        $dropoffLocationId = rs_string($body['dropoffLocationId'] ?? '');
        $pickupDate        = rs_date($body['pickupDate'] ?? '');
        $dropoffDate       = rs_date($body['dropoffDate'] ?? '');
        $driverAge         = rs_int($body['driverAge'] ?? 30, 30);
        $currency          = rs_currency($body['currency'] ?? 'GBP');

        if ($pickupLocationId === '') rs_error_exit(400, 'pickupLocationId is required.');
        if ($pickupDate === null) rs_error_exit(400, 'pickupDate must be YYYY-MM-DD.');
        if ($dropoffDate === null) rs_error_exit(400, 'dropoffDate must be YYYY-MM-DD.');
        if (strtotime($pickupDate) >= strtotime($dropoffDate)) {
            rs_error_exit(400, 'dropoffDate must be after pickupDate.');
        }
        if ($driverAge < 18 || $driverAge > 99) rs_error_exit(400, 'driverAge must be 18–99.');

        $payload = [
            'pickupLocationId' => $pickupLocationId,
            'pickupDate'       => $pickupDate,
            'dropoffDate'      => $dropoffDate,
            'driverAge'        => $driverAge,
            'currency'         => $currency,
        ];
        if ($dropoffLocationId !== '') $payload['dropoffLocationId'] = $dropoffLocationId;

        $result = rs_bridge_post('/car/search', $payload, 25);
        $status = ($result['success'] ?? false) ? 200
            : (str_contains($result['message'] ?? '', 'No car') ? 422 : 502);
        rs_json_exit($result, $status);

    // ------------------------------------------------------------------
    case 'checkout':
        // Passthrough validated body (remove action key first)
        unset($body['action']);
        $result = rs_bridge_post('/car/checkout', $body, 20);
        rs_json_exit($result, ($result['success'] ?? false) ? 200 : 502);

    // ------------------------------------------------------------------
    default:
        rs_error_exit(400, 'action must be one of: locations, search, checkout.');
}
