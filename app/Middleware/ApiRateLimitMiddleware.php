<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';
require_once __DIR__ . '/../Security/ApiRateLimiter.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';

final class ApiRateLimitMiddleware extends Middleware
{
    public function __construct(private ?ApiRateLimiter $limiter = null)
    {
        $this->limiter ??= new ApiRateLimiter();
    }

    public function handle(array $request): void
    {
        $this->limiter->consume($this->identity());
    }

    private function identity(): string
    {
        $principal = PrincipalContext::current();
        if ($principal !== null && $principal->authenticationType === 'session' && $principal->userId !== null) {
            return 'session:' . $principal->userId;
        }
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
        if ($principal !== null && $principal->authenticationType === 'api_key' && is_string($apiKey) && $apiKey !== '') {
            return 'api-key:' . hash('sha256', $apiKey);
        }
        return 'anonymous:' . SecurityConfiguration::clientIp();
    }
}
