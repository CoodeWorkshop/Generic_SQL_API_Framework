<?php

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$isAdminPath = $path === '/admin' || str_starts_with($path, '/admin/');

if ($path === '/health') {
    $application = require __DIR__ . '/../config/app.php';
    $port = (int)(getenv('GENERIC_API_PORT') ?: ($_SERVER['SERVER_PORT'] ?? 0));
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'status' => 'healthy',
        'version' => (string)($application['version'] ?? 'unknown'),
        'port' => $port,
        'startedAt' => getenv('GENERIC_API_STARTED_AT') ?: null,
        'uptimeSeconds' => ($started = strtotime((string)getenv('GENERIC_API_STARTED_AT')))
            ? max(0, time() - $started) : null,
    ], JSON_UNESCAPED_SLASHES);
    return true;
}

$candidate = __DIR__ . $path;
if ($path !== '/' && is_file($candidate)) {
    return false;
}

if ($isAdminPath) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    return true;
}

if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found.';
return true;
