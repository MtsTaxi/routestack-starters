<?php
/**
 * Create a new flight search session
 *
 * POST /ai-travel-api/flight-session.php
 * Body  : {} (empty)
 * Returns: { "success": true, "sessionId": "..." }
 *
 * Call this once before flight-locations.php or flight-search.php.
 * Store the sessionId in the browser and pass it to subsequent calls.
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

try {
    $json = rs_call_tool('flight_session', [], 10);
} catch (RuntimeException $e) {
    rs_error_exit(502, 'RouteStack error: ' . $e->getMessage());
}

$sessionId = $json['sessionId']         ??
             ($json['result']['sessionId'] ?? null);

if (!is_string($sessionId) || $sessionId === '') {
    rs_error_exit(502, 'No sessionId returned from RouteStack.');
}

rs_json_exit(['success' => true, 'sessionId' => $sessionId]);
