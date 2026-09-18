<?php

require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Security/PasswordHasher.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/AuthSessionService.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../Security/LoginRateLimiter.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';

final class AuthService
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$.ZDQbluvjYCUXzPdpk4XxeI6c5A/kw5XFuSoh7OWgjT7S4U/pneOK';

    private AuthRepository $authRepository;
    private PasswordHasher $passwordHasher;
    private AuthSessionService $sessionService;
    private LoginRateLimiter $rateLimiter;

    public function __construct(
        ?AuthRepository $authRepository = null,
        ?PasswordHasher $passwordHasher = null,
        ?AuthSessionService $sessionService = null,
        ?LoginRateLimiter $rateLimiter = null
    ) {
        $this->authRepository = $authRepository ?? new AuthRepository();
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
        $this->sessionService = $sessionService ?? new AuthSessionService();
        $this->rateLimiter = $rateLimiter ?? new LoginRateLimiter();
    }

    public function login(string $username, string $password): array
    {
        try {
            $sourceIp = SecurityConfiguration::clientIp();
            try {
                $this->rateLimiter->assertAllowed($sourceIp, $username);
            } catch (ApiRequestException $exception) {
                (new Logger())->security('login_rate_limited', [
                    'username' => $username,
                    'sourceIp' => $sourceIp,
                    'result' => 'rejected',
                ]);
                throw $exception;
            }

            $user = $this->authRepository->findUser($username);
            $verified = $this->passwordHasher->verify(
                $password,
                $user['passwordHash'] ?? self::DUMMY_PASSWORD_HASH
            );
            if ($user === null || !$verified || $user['enabled'] !== true) {
                $blocked = $this->rateLimiter->recordFailure($sourceIp, $username);
                (new Logger())->security(
                    $user !== null && $verified && $user['enabled'] !== true
                        ? 'disabled_account_login_attempt'
                        : 'login_failed',
                    ['username' => $username, 'sourceIp' => $sourceIp, 'result' => 'rejected']
                );
                if ($blocked) {
                    (new Logger())->security('login_rate_limited', [
                        'username' => $username,
                        'sourceIp' => $sourceIp,
                        'result' => 'blocked',
                    ]);
                    $this->rateLimiter->throwRateLimited();
                }
                throw new ApiRequestException(
                    'Invalid username or password.',
                    'INVALID_CREDENTIALS',
                    [],
                    401
                );
            }

            $this->rateLimiter->reset($sourceIp, $username);
            if ($this->passwordHasher->needsRehash($user['passwordHash'])) {
                try {
                    $this->authRepository->replacePasswordHash(
                        $user['username'],
                        $user['passwordHash'],
                        $this->passwordHasher->hash($password)
                    );
                } catch (Throwable $exception) {
                    (new Logger())->security('password_rehash_failed', [
                        'username' => $user['username'],
                        'sourceIp' => $sourceIp,
                        'result' => 'login_continued',
                    ]);
                }
            }
            $this->sessionService->establish($user['username'], $user['isAdmin']);
            (new Logger())->security('login_succeeded', [
                'username' => $user['username'],
                'sourceIp' => $sourceIp,
                'result' => 'authenticated',
            ]);
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
            $username = (string)$this->sessionService->authenticatedUsername();
            $isAdmin = $this->sessionService->authenticatedUserIsAdmin();
            $user = $this->authRepository->findUser($username);
            if ($user === null || $user['enabled'] !== true || $user['isAdmin'] !== $isAdmin) {
                $this->sessionService->destroy();
                return $this->unauthenticatedSnapshot();
            }
            return $this->authenticatedSnapshot(
                $user['username'],
                $user['isAdmin']
            );
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function logout(): array
    {
        try {
            $username = $this->sessionService->resume()
                ? $this->sessionService->authenticatedUsername()
                : null;
            $this->sessionService->destroy();
            (new Logger())->security('logout', [
                'username' => $username,
                'sourceIp' => SecurityConfiguration::clientIp(),
                'result' => 'completed',
            ]);
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
