<?php

require_once __DIR__ . '/../app/Health/ApplicationHealthMonitor.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(['status' => 'unhealthy', 'category' => 'method_not_allowed']);
    return;
}

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/health', PHP_URL_PATH) ?: '/health');
$monitor = new ApplicationHealthMonitor();
if ($path === '/health/ready') {
    $payload = $monitor->readiness();
    http_response_code($payload['status'] === 'healthy' ? 200 : 503);
} else {
    $port = (int)(getenv('GENERIC_API_PORT') ?: ($_SERVER['SERVER_PORT'] ?? 0));
    $payload = $monitor->liveness('api', $port, getenv('GENERIC_API_STARTED_AT') ?: null);
    if ($path === '/health/live') {
        $payload = ['status' => $payload['status'], 'service' => $payload['service'], 'version' => $payload['version']];
    }
    http_response_code(200);
}
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
