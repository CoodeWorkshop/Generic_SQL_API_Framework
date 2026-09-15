<?php

require_once __DIR__ . '/../../app/Requests/QueryRequestValidator.php';

class SqlBackendCapabilities
{
    private static ?array $functions = null;

    public static function functions(): array
    {
        if (self::$functions === null) {
            $constant = (new ReflectionClass(QueryRequestValidator::class))
                ->getReflectionConstant('FUNCTIONS');
            self::$functions = $constant === false ? [] : $constant->getValue();
        }
        return self::$functions;
    }

    public static function supportsFunction(string $function): bool
    {
        return in_array(strtoupper($function), self::functions(), true);
    }
}
