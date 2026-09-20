<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class LocalAdminMiddleware extends Middleware
{
    public function handle(array $request): void
    {
        if (!str_starts_with((string)($request['action'] ?? ''), 'admin.')) {
            return;
        }
        $enabled = getenv('GENERIC_ADMIN_ENABLED');
        $address = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($enabled !== '1' || !in_array($address, ['127.0.0.1', '::1'], true)) {
            throw new ApiRequestException('Resource not found.', 'NOT_FOUND', [], 404);
        }
    }
}
