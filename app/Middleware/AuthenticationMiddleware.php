<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Services/AuthSessionService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Security/ApiKeyAuthenticator.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';

final class AuthenticationMiddleware extends Middleware
{
    private bool $enforce;
    private array $publicActions;
    private AuthSessionService $session;
    private AuthRepository $authRepository;
    private ApiKeyAuthenticator $apiKeys;

    public function __construct(
        bool $enforce = false,
        array $publicActions = [],
        ?AuthSessionService $session = null,
        ?AuthRepository $authRepository = null,
        ?ApiKeyAuthenticator $apiKeys = null
    ) {
        $this->enforce = $enforce;
        $this->publicActions = $publicActions;
        $this->session = $session ?? new AuthSessionService();
        $this->authRepository = $authRepository ?? new AuthRepository();
        $this->apiKeys = $apiKeys ?? new ApiKeyAuthenticator();
    }

    public function handle(array $request): void
    {
        unset($_SERVER['GENERIC_AUTH_PROVIDER']);
        if (!$this->enforce || in_array($request['action'] ?? null, $this->publicActions, true)) {
            return;
        }

        $action = (string)($request['action'] ?? '');
        if (str_starts_with($action, 'admin.') || str_starts_with($action, 'auth.')) {
            $this->requireSession();
            return;
        }

        $mode = SecurityConfiguration::authenticationMode();
        if ($mode === 'none') {
            $_SERVER['GENERIC_AUTH_PROVIDER'] = 'none';
            return;
        }
        if (($mode === 'api_key' || $mode === 'session+api_key') && $this->validApiKey()) {
            $_SERVER['GENERIC_AUTH_PROVIDER'] = 'api_key';
            return;
        }
        if ($mode === 'api_key') {
            if (!$this->apiKeys->configured()) {
                throw new ApiRequestException(
                    'API key authentication is not configured.',
                    'AUTHENTICATION_UNAVAILABLE',
                    [],
                    503
                );
            }
            $this->authenticationRequired();
        }
        $this->requireSession();
    }

    private function requireSession(): void
    {
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

    private function validApiKey(): bool
    {
        $provided = $_SERVER['HTTP_X_API_KEY'] ?? null;
        return $this->apiKeys->authenticate(is_string($provided) ? $provided : null);
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
