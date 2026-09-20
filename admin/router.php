<?php

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    echo 'Not found.';
    return true;
}

$candidate = __DIR__ . $path;
if ($path !== '/' && is_file($candidate)) return false;
if ($path === '/api.php') {
    require __DIR__ . '/api.php';
    return true;
}
if ($path === '/' || $path === '/admin' || str_starts_with($path, '/admin/')) {
    require __DIR__ . '/index.php';
    return true;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found.';
return true;
