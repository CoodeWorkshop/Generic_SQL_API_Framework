<?php

final class RuntimeControls
{
    public const QUERY_TIMEOUT_MINIMUM = 1;
    public const QUERY_TIMEOUT_MAXIMUM = 300;
    public const API_REQUESTS_MINIMUM = 1;
    public const API_REQUESTS_MAXIMUM = 10000;
    public const RATE_WINDOW_MINIMUM = 1;
    public const RATE_WINDOW_MAXIMUM = 86400;
    public const LOGIN_ATTEMPTS_MINIMUM = 2;
    public const LOGIN_ATTEMPTS_MAXIMUM = 100;
    public const LOGIN_WINDOW_MINIMUM = 60;
    public const LOGIN_LOCKOUT_MINIMUM = 30;
    public const SESSION_IDLE_MINIMUM = 60;
    public const SESSION_ABSOLUTE_MINIMUM = 300;
    public const SESSION_TIMEOUT_MAXIMUM = 2592000;
    public const BODY_BYTES_MINIMUM = 1024;
    public const BODY_BYTES_MAXIMUM = 104857600;
    public const PAGE_SIZE_MINIMUM = 1;
    public const PAGE_SIZE_MAXIMUM = 10000;

    public static function defaults(): array
    {
        return [
            'query' => ['timeoutSeconds' => 45],
            'rateLimit' => [
                'api' => ['enabled' => true, 'requests' => 600, 'windowSeconds' => 60],
                'login' => ['enabled' => true, 'maximumAttempts' => 5, 'windowSeconds' => 900, 'lockoutSeconds' => 300],
            ],
            'session' => ['idleTimeoutSeconds' => 1800, 'absoluteTimeoutSeconds' => 28800],
            'request' => ['maxBodyBytes' => 1048576, 'defaultPageSize' => 25, 'maxPageSize' => 1000],
        ];
    }

    public static function validate(array $runtime): void
    {
        self::exactObject($runtime, ['query', 'rateLimit', 'session', 'request'], 'runtime');
        self::exactObject($runtime['query'] ?? null, ['timeoutSeconds'], 'runtime.query');
        self::integer($runtime['query']['timeoutSeconds'] ?? null, self::QUERY_TIMEOUT_MINIMUM, self::QUERY_TIMEOUT_MAXIMUM, 'runtime.query.timeoutSeconds');

        self::exactObject($runtime['rateLimit'] ?? null, ['api', 'login'], 'runtime.rateLimit');
        self::exactObject($runtime['rateLimit']['api'] ?? null, ['enabled', 'requests', 'windowSeconds'], 'runtime.rateLimit.api');
        self::boolean($runtime['rateLimit']['api']['enabled'] ?? null, 'runtime.rateLimit.api.enabled');
        self::integer($runtime['rateLimit']['api']['requests'] ?? null, self::API_REQUESTS_MINIMUM, self::API_REQUESTS_MAXIMUM, 'runtime.rateLimit.api.requests');
        self::integer($runtime['rateLimit']['api']['windowSeconds'] ?? null, self::RATE_WINDOW_MINIMUM, self::RATE_WINDOW_MAXIMUM, 'runtime.rateLimit.api.windowSeconds');

        self::exactObject($runtime['rateLimit']['login'] ?? null, ['enabled', 'maximumAttempts', 'windowSeconds', 'lockoutSeconds'], 'runtime.rateLimit.login');
        self::boolean($runtime['rateLimit']['login']['enabled'] ?? null, 'runtime.rateLimit.login.enabled');
        self::integer($runtime['rateLimit']['login']['maximumAttempts'] ?? null, self::LOGIN_ATTEMPTS_MINIMUM, self::LOGIN_ATTEMPTS_MAXIMUM, 'runtime.rateLimit.login.maximumAttempts');
        self::integer($runtime['rateLimit']['login']['windowSeconds'] ?? null, self::LOGIN_WINDOW_MINIMUM, self::RATE_WINDOW_MAXIMUM, 'runtime.rateLimit.login.windowSeconds');
        self::integer($runtime['rateLimit']['login']['lockoutSeconds'] ?? null, self::LOGIN_LOCKOUT_MINIMUM, self::RATE_WINDOW_MAXIMUM, 'runtime.rateLimit.login.lockoutSeconds');

        self::exactObject($runtime['session'] ?? null, ['idleTimeoutSeconds', 'absoluteTimeoutSeconds'], 'runtime.session');
        self::integer($runtime['session']['idleTimeoutSeconds'] ?? null, self::SESSION_IDLE_MINIMUM, self::SESSION_TIMEOUT_MAXIMUM, 'runtime.session.idleTimeoutSeconds');
        self::integer($runtime['session']['absoluteTimeoutSeconds'] ?? null, self::SESSION_ABSOLUTE_MINIMUM, self::SESSION_TIMEOUT_MAXIMUM, 'runtime.session.absoluteTimeoutSeconds');
        if (($runtime['session']['idleTimeoutSeconds'] ?? PHP_INT_MAX) > ($runtime['session']['absoluteTimeoutSeconds'] ?? 0)) {
            throw new InvalidArgumentException('runtime.session.idleTimeoutSeconds must not exceed the absolute timeout.');
        }

        self::exactObject($runtime['request'] ?? null, ['maxBodyBytes', 'defaultPageSize', 'maxPageSize'], 'runtime.request');
        self::integer($runtime['request']['maxBodyBytes'] ?? null, self::BODY_BYTES_MINIMUM, self::BODY_BYTES_MAXIMUM, 'runtime.request.maxBodyBytes');
        self::integer($runtime['request']['defaultPageSize'] ?? null, self::PAGE_SIZE_MINIMUM, self::PAGE_SIZE_MAXIMUM, 'runtime.request.defaultPageSize');
        self::integer($runtime['request']['maxPageSize'] ?? null, self::PAGE_SIZE_MINIMUM, self::PAGE_SIZE_MAXIMUM, 'runtime.request.maxPageSize');
        if (($runtime['request']['defaultPageSize'] ?? PHP_INT_MAX) > ($runtime['request']['maxPageSize'] ?? 0)) {
            throw new InvalidArgumentException('runtime.request.defaultPageSize must not exceed the maximum page size.');
        }
    }

    private static function exactObject($value, array $keys, string $path): void
    {
        if (!is_array($value) || array_is_list($value) || array_keys($value) !== $keys) {
            throw new InvalidArgumentException("{$path} must contain the expected configuration properties.");
        }
    }

    private static function integer($value, int $minimum, int $maximum, string $path): void
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("{$path} must be an integer between {$minimum} and {$maximum}.");
        }
    }

    private static function boolean($value, string $path): void
    {
        if (!is_bool($value)) throw new InvalidArgumentException("{$path} must be boolean.");
    }
}
