<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Services/AuthSessionService.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';
require_once __DIR__ . '/../Services/AuthorizationService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class AdminAuthorizationMiddleware extends Middleware
{
    private array $adminActions;
    private AuthSessionService $session;
    private AuthorizationService $authorization;

    public function __construct(array $adminActions, ?AuthSessionService $session = null, ?AuthorizationService $authorization = null)
    {
        $this->adminActions = $adminActions;
        $this->session = $session ?? new AuthSessionService();
        $this->authorization = $authorization ?? new AuthorizationService();
    }

    public function handle(array $request): void
    {
        if (!in_array($request['action'] ?? null, $this->adminActions, true)) {
            return;
        }
        if (!$this->session->isAuthenticated()) {
            throw new ApiRequestException('Authentication required.', 'AUTHENTICATION_REQUIRED', [], 401);
        }
        $principal = PrincipalContext::current();
        if ($principal === null) throw new ApiRequestException('Authentication required.', 'AUTHENTICATION_REQUIRED', [], 401);
        $this->authorization->authorize($principal, 'admin.manage');
    }
}
