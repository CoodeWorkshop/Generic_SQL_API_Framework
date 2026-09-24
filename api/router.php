<?php

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$isAdminPath = $path === '/admin' || str_starts_with($path, '/admin/');

if (in_array($path, ['/health', '/health/live', '/health/ready'], true)) {
    require __DIR__ . '/health.php';
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
require_once __DIR__ . '/../core/Response.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(Response::errorPayload('Not found.', 'NOT_FOUND'), JSON_UNESCAPED_SLASHES);
return true;
