<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Security/CsrfTokenService.php';

final class CsrfProtectionMiddleware extends Middleware
{
    private const PROTECTED_ACTIONS = [
        'auth.login',
        'auth.logout',
        'auth.users.create',
        'auth.users.update',
        'auth.users.enable',
        'auth.users.disable',
        'auth.users.delete',
        'auth.users.changePassword',
        'setup.createAdmin',
        'admin.database.test',
        'admin.database.save',
        'admin.server.save',
        'admin.features.save',
        'admin.api.start',
        'admin.api.stop',
        'admin.api.restart',
        'admin.cors.save',
        'admin.authentication.save',
        'insert',
        'update',
        'delete',
        'upsert',
    ];

    private CsrfTokenService $tokens;

    public function __construct(?CsrfTokenService $tokens = null)
    {
        $this->tokens = $tokens ?? new CsrfTokenService();
    }

    public function handle(array $request): void
    {
        if (in_array($_SERVER['GENERIC_AUTH_PROVIDER'] ?? null, ['api_key', 'none'], true)) {
            return;
        }
        if (!in_array($request['action'] ?? null, self::PROTECTED_ACTIONS, true)) {
            return;
        }
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $this->tokens->validate(is_string($header) ? $header : null);
    }
}
