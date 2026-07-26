<?php
/**
 * Hotel destination autocomplete
 * POST /ai-travel-api/hotel-destinations.php
 * Body (JSON): { "query": "London" }
 * Returns: { "success": true, "destinations": [...] }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bridge-client.php';

rs_bootstrap();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rs_error_exit(405, 'Method not allowed.');
}

// CSRF validation
rs_csrf_validate();

// Parse JSON body
$raw  = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : [];
if (!is_array($body)) {
    rs_error_exit(400, 'Invalid JSON body.');
}

$query = rs_string($body['query'] ?? '');

if (strlen($query) < 2) {
    rs_error_exit(400, 'query must be at least 2 characters.');
}

if (strlen($query) > 200) {
    rs_error_exit(400, 'query is too long.');
}

// Forward to bridge
$result = rs_bridge_post('/hotel/destinations', ['query' => $query], 10);

rs_json_exit($result, $result['success'] ?? false ? 200 : 502);
