<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/AuthService.php';

final class AuthController extends BaseController
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    public function login(array $request): void
    {
        $this->success([$this->authService->login($request['username'], $request['password'])], 'Login successful.');
    }

    public function session(array $request): void
    {
        $this->success([$this->authService->session()], 'Session state loaded.');
    }

    public function logout(array $request): void
    {
        $this->success([$this->authService->logout()], 'Logout successful.');
    }
}
