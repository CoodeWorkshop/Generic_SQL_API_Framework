<?php

require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';

final class SecurityConfiguration
{
    public static function isProduction(): bool
    {
        return strtolower((string)(getenv('GENERIC_APP_ENV') ?: 'development')) === 'production';
    }

    public static function allowedOrigins(): array
    {
        $configured = trim((string)(getenv('GENERIC_API_ALLOWED_ORIGINS') ?: ''));
        if ($configured !== '') {
            $origins = [];
            foreach (array_filter(array_map('trim', explode(',', $configured))) as $origin) {
                try {
                    $normalized = AdminConfigurationRepository::normalizeOrigin($origin);
                    if ($normalized === $origin && !in_array($origin, $origins, true)) {
                        $origins[] = $origin;
                    }
                } catch (InvalidArgumentException $exception) {
                    // Invalid deployment overrides fail closed instead of being reflected.
                }
            }
            return $origins;
        }

        return self::configuration()['cors']['allowedOrigins'];
    }

    public static function corsCredentialsEnabled(): bool
    {
        return self::configuration()['cors']['credentialsEnabled'];
    }

    public static function corsAllowedMethods(): array
    {
        return self::configuration()['cors']['allowedMethods'];
    }

    public static function authenticationMode(): string
    {
        return self::configuration()['authentication']['mode'];
    }

    public static function sessionOptions(): array
    {
        return [
            'secure' => self::isProduction() || self::directHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
            'idleTimeout' => self::integerEnvironment('GENERIC_SESSION_IDLE_TIMEOUT', 1800, 60),
            'absoluteTimeout' => self::integerEnvironment('GENERIC_SESSION_ABSOLUTE_TIMEOUT', 28800, 300),
        ];
    }

    public static function loginRateLimitOptions(): array
    {
        return [
            'enabled' => PHP_SAPI !== 'cli',
            'maximumAttempts' => self::integerEnvironment('GENERIC_LOGIN_MAX_ATTEMPTS', 5, 2),
            'windowSeconds' => self::integerEnvironment('GENERIC_LOGIN_WINDOW_SECONDS', 900, 60),
            'lockoutSeconds' => self::integerEnvironment('GENERIC_LOGIN_LOCKOUT_SECONDS', 300, 30),
        ];
    }

    public static function clientIp(): string
    {
        $value = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return is_string($value) && $value !== '' ? substr($value, 0, 64) : 'unknown';
    }

    private static function directHttpsRequest(): bool
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        return $https === 'on' || $https === '1';
    }

    private static function configuration(): array
    {
        try {
            return (new AdminConfigurationRepository())->load();
        } catch (Throwable $exception) {
            $defaults = AdminConfigurationRepository::defaults();
            if (self::isProduction()) {
                $defaults['cors']['allowedOrigins'] = [];
            }
            return $defaults;
        }
    }

    private static function integerEnvironment(string $name, int $default, int $minimum): int
    {
        $value = getenv($name);
        if ($value === false || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }
        return max($minimum, (int)$value);
    }
}
