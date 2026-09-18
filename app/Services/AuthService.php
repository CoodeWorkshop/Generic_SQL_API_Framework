<?php

require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Security/PasswordHasher.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/AuthSessionService.php';
require_once __DIR__ . '/../../core/Logger.php';

final class AuthService
{
    private AuthRepository $authRepository;
    private PasswordHasher $passwordHasher;
    private AuthSessionService $sessionService;

    public function __construct(
        ?AuthRepository $authRepository = null,
        ?PasswordHasher $passwordHasher = null,
        ?AuthSessionService $sessionService = null
    ) {
        $this->authRepository = $authRepository ?? new AuthRepository();
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
        $this->sessionService = $sessionService ?? new AuthSessionService();
    }

    public function login(string $username, string $password): array
    {
        try {
            $user = $this->authRepository->findUser($username);
            if ($user === null
                || !$this->passwordHasher->verify($password, $user['passwordHash'])
                || $user['enabled'] !== true) {
                throw new ApiRequestException(
                    'Invalid username or password.',
                    'INVALID_CREDENTIALS',
                    [],
                    401
                );
            }

            $this->sessionService->establish($user['username'], $user['isAdmin']);
            return $this->authenticatedSnapshot($user['username'], $user['isAdmin']);
        } catch (ApiRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function session(): array
    {
        try {
            if (!$this->sessionService->resume() || !$this->sessionService->isAuthenticated()) {
                return $this->unauthenticatedSnapshot();
            }
            return $this->authenticatedSnapshot(
                (string)$this->sessionService->authenticatedUsername(),
                $this->sessionService->authenticatedUserIsAdmin()
            );
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function logout(): array
    {
        try {
            $this->sessionService->destroy();
            return $this->unauthenticatedSnapshot();
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    private function authenticatedSnapshot(string $username, bool $isAdmin): array
    {
        return [
            'authenticated' => true,
            'user' => ['username' => $username, 'isAdmin' => $isAdmin],
        ];
    }

    private function unauthenticatedSnapshot(): array
    {
        return ['authenticated' => false, 'user' => null];
    }

    private function fail(Throwable $exception): never
    {
        (new Logger())->write((string)json_encode([
            'timestamp' => date(DATE_ATOM),
            'requestId' => defined('API_REQUEST_ID') ? API_REQUEST_ID : null,
            'event' => 'authentication_error',
            'errorType' => get_class($exception),
        ], JSON_UNESCAPED_SLASHES));
        throw new ApiRequestException(
            'Unable to complete authentication request.',
            'AUTHENTICATION_FAILED',
            [],
            500
        );
    }
}
