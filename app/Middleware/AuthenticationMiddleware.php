<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Services/AuthSessionService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class AuthenticationMiddleware extends Middleware
{
    private bool $enforce;
    private array $publicActions;
    private AuthSessionService $session;

    public function __construct(
        bool $enforce = false,
        array $publicActions = [],
        ?AuthSessionService $session = null
    ) {
        $this->enforce = $enforce;
        $this->publicActions = $publicActions;
        $this->session = $session ?? new AuthSessionService();
    }

    public function handle(array $request): void
    {
        if (!$this->enforce || in_array($request['action'] ?? null, $this->publicActions, true)) {
            return;
        }

        if (!$this->session->resume() || !$this->session->isAuthenticated()) {
            throw new ApiRequestException(
                'Authentication required.',
                'AUTHENTICATION_REQUIRED',
                [],
                401
            );
        }
    }
}
