<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Services/AuthSessionService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class AdminAuthorizationMiddleware extends Middleware
{
    private array $adminActions;
    private AuthSessionService $session;

    public function __construct(array $adminActions, ?AuthSessionService $session = null)
    {
        $this->adminActions = $adminActions;
        $this->session = $session ?? new AuthSessionService();
    }

    public function handle(array $request): void
    {
        if (!in_array($request['action'] ?? null, $this->adminActions, true)) {
            return;
        }
        if (!$this->session->isAuthenticated()) {
            throw new ApiRequestException('Authentication required.', 'AUTHENTICATION_REQUIRED', [], 401);
        }
        if (!$this->session->authenticatedUserIsAdmin()) {
            throw new ApiRequestException(
                'Administrator access is required.',
                'ADMIN_REQUIRED',
                [],
                403
            );
        }
    }
}
