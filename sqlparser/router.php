<?php

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($path === '/health') {
    $application = require __DIR__ . '/../config/app.php';
    $port = (int)(getenv('GENERIC_SQLPARSER_PORT') ?: ($_SERVER['SERVER_PORT'] ?? 0));
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'status' => 'healthy',
        'service' => 'sqlparser',
        'version' => (string)($application['version'] ?? 'unknown'),
        'port' => $port,
        'startedAt' => getenv('GENERIC_SQLPARSER_STARTED_AT') ?: null,
    ], JSON_UNESCAPED_SLASHES);
    return true;
}

$candidate = __DIR__ . $path;
if ($path !== '/' && is_file($candidate)) return false;
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
require_once __DIR__ . '/../core/Response.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(Response::errorPayload('Not found.', 'NOT_FOUND'), JSON_UNESCAPED_SLASHES);
return true;
