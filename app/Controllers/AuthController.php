<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/AuthService.php';
require_once __DIR__ . '/../Security/CsrfTokenService.php';

final class AuthController extends BaseController
{
    private AuthService $authService;
    private CsrfTokenService $csrfTokens;

    public function __construct(?AuthService $authService = null, ?CsrfTokenService $csrfTokens = null)
    {
        $this->authService = $authService ?? new AuthService();
        $this->csrfTokens = $csrfTokens ?? new CsrfTokenService();
    }

    public function login(array $request): void
    {
        $snapshot = $this->authService->login($request['username'], $request['password']);
        header('X-CSRF-Token: ' . $this->csrfTokens->rotate());
        $this->success([$snapshot], 'Login successful.');
    }

    public function session(array $request): void
    {
        $this->success([$this->authService->session()], 'Session state loaded.');
    }

    public function csrf(array $request): void
    {
        $this->success([['csrfToken' => $this->csrfTokens->token()]], 'Security token loaded.');
    }

    public function logout(array $request): void
    {
        $this->success([$this->authService->logout()], 'Logout successful.');
    }
}
