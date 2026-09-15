<?php

require_once __DIR__ . '/../../app/Repositories/Query/QueryFunctionRegistry.php';

class SqlBackendCapabilities
{
    public static function functions(): array
    {
        return QueryFunctionRegistry::all();
    }

    public static function supportsFunction(string $function): bool
    {
        return in_array(strtoupper($function), self::functions(), true);
    }
}
