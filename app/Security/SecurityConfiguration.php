<?php

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
            return array_values(array_filter(array_map('trim', explode(',', $configured))));
        }

        return self::isProduction() ? [] : [
            'http://127.0.0.1:5314',
            'http://127.0.0.1:5173',
            'http://localhost:5314',
            'http://localhost:5173',
        ];
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

    private static function integerEnvironment(string $name, int $default, int $minimum): int
    {
        $value = getenv($name);
        if ($value === false || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }
        return max($minimum, (int)$value);
    }
}
