<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Services/AuthSessionService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Repositories/AuthRepository.php';

final class AuthenticationMiddleware extends Middleware
{
    private bool $enforce;
    private array $publicActions;
    private AuthSessionService $session;
    private AuthRepository $authRepository;

    public function __construct(
        bool $enforce = false,
        array $publicActions = [],
        ?AuthSessionService $session = null,
        ?AuthRepository $authRepository = null
    ) {
        $this->enforce = $enforce;
        $this->publicActions = $publicActions;
        $this->session = $session ?? new AuthSessionService();
        $this->authRepository = $authRepository ?? new AuthRepository();
    }

    public function handle(array $request): void
    {
        if (!$this->enforce || in_array($request['action'] ?? null, $this->publicActions, true)) {
            return;
        }

        if (!$this->session->resume() || !$this->session->isAuthenticated()) {
            $this->authenticationRequired();
        }

        try {
            $user = $this->authRepository->findUser((string)$this->session->authenticatedUsername());
        } catch (Throwable $exception) {
            throw new ApiRequestException(
                'Unable to validate authentication.',
                'AUTHENTICATION_UNAVAILABLE',
                [],
                500
            );
        }
        if ($user === null
            || $user['enabled'] !== true
            || $user['isAdmin'] !== $this->session->authenticatedUserIsAdmin()) {
            $this->session->destroy();
            $this->authenticationRequired();
        }
    }

    private function authenticationRequired(): never
    {
        throw new ApiRequestException(
            'Authentication required.',
            'AUTHENTICATION_REQUIRED',
            [],
            401
        );
    }
}
