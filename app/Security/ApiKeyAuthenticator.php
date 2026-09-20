<?php

final class ApiKeyAuthenticator
{
    public const ENVIRONMENT_VARIABLE = 'GENERIC_SQL_API_KEY';

    public function configured(): bool
    {
        $expected = getenv(self::ENVIRONMENT_VARIABLE);
        return $expected !== false && strlen($expected) >= 32;
    }

    public function authenticate(?string $provided): bool
    {
        $expected = getenv(self::ENVIRONMENT_VARIABLE);
        return $expected !== false
            && strlen($expected) >= 32
            && is_string($provided)
            && strlen($provided) >= 32
            && hash_equals($expected, $provided);
    }
}
