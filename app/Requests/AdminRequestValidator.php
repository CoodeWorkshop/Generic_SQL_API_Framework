<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';

final class AdminRequestValidator
{
    private const SIMPLE_ACTIONS = [
        'admin.status',
        'admin.database.get',
        'admin.settings.get',
    ];

    public function validate(array $request): array
    {
        $action = $request['action'] ?? null;
        if (in_array($action, self::SIMPLE_ACTIONS, true)) {
            $this->rejectUnknown($request, ['action']);
            return ['action' => $action];
        }
        if (in_array($action, ['admin.database.test', 'admin.database.save'], true)) {
            $this->rejectUnknown($request, ['action', 'database']);
            return ['action' => $action, 'database' => $this->database($request['database'] ?? null)];
        }
        if ($action === 'admin.cors.save') {
            $this->rejectUnknown($request, ['action', 'cors']);
            return ['action' => $action, 'cors' => $this->cors($request['cors'] ?? null)];
        }
        if ($action === 'admin.authentication.save') {
            $this->rejectUnknown($request, ['action', 'mode']);
            $mode = $request['mode'] ?? null;
            if (!in_array($mode, AdminConfigurationRepository::AUTHENTICATION_MODES, true)) {
                $this->invalid([['path' => 'mode', 'message' => 'Unsupported authentication mode.']]);
            }
            return ['action' => $action, 'mode' => $mode];
        }
        if ($action === 'admin.sqlParser.convert') {
            $this->rejectUnknown($request, ['action', 'sql']);
            $sql = $request['sql'] ?? null;
            if (!is_string($sql) || trim($sql) === '' || strlen($sql) > 200000) {
                $this->invalid([['path' => 'sql', 'message' => 'SQL must be a non-empty string of at most 200,000 bytes.']]);
            }
            return ['action' => $action, 'sql' => $sql];
        }
        throw new ApiRequestException('Invalid admin request.', 'INVALID_ADMIN_REQUEST');
    }

    private function database($value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid([['path' => 'database', 'message' => 'Database configuration must be an object.']]);
        }
        $this->rejectUnknown($value, [
            'provider', 'driver', 'server', 'port', 'database', 'authentication',
            'username', 'password', 'encrypt', 'trustServerCertificate',
        ], 'database.');
        $errors = [];
        $provider = strtolower(trim((string)($value['provider'] ?? '')));
        if ($provider !== 'sqlserver') {
            $errors[] = ['path' => 'database.provider', 'message' => 'Only sqlserver is currently supported.'];
        }
        $driver = trim((string)($value['driver'] ?? ''));
        if ($driver !== 'auto' && !in_array($driver, SqlServerDriver::supportedDrivers(), true)) {
            $errors[] = ['path' => 'database.driver', 'message' => 'Unsupported SQL Server ODBC driver.'];
        }
        $server = $this->connectionStringValue($value['server'] ?? null, 'server', true, $errors);
        $database = $this->connectionStringValue($value['database'] ?? null, 'database', true, $errors);
        $username = $this->connectionStringValue($value['username'] ?? '', 'username', false, $errors);
        $authentication = strtolower(trim((string)($value['authentication'] ?? '')));
        if (!in_array($authentication, ['sql', 'windows'], true)) {
            $errors[] = ['path' => 'database.authentication', 'message' => 'Authentication must be sql or windows.'];
        }
        if ($authentication === 'sql' && $username === '') {
            $errors[] = ['path' => 'database.username', 'message' => 'Username is required for SQL authentication.'];
        }
        $port = $value['port'] ?? null;
        if ($port === '') $port = null;
        if ($port !== null && (filter_var($port, FILTER_VALIDATE_INT) === false
            || (int)$port < 1 || (int)$port > 65535)) {
            $errors[] = ['path' => 'database.port', 'message' => 'Port must be between 1 and 65535.'];
        }
        $password = $value['password'] ?? null;
        if ($password !== null && (!is_string($password) || strlen($password) > 4096)) {
            $errors[] = ['path' => 'database.password', 'message' => 'Password must be a string of at most 4096 bytes.'];
        }
        foreach (['encrypt', 'trustServerCertificate'] as $field) {
            if (!is_bool($value[$field] ?? null)) {
                $errors[] = ['path' => 'database.' . $field, 'message' => 'Value must be boolean.'];
            }
        }
        if ($errors !== []) $this->invalid($errors);

        return [
            'provider' => $provider,
            'driver' => $driver,
            'server' => $server,
            'database' => $database,
            'authentication' => $authentication,
            'username' => $username,
            'password' => $password,
            'port' => $port === null ? null : (string)(int)$port,
            'options' => [
                'encrypt' => $value['encrypt'],
                'trustServerCertificate' => $value['trustServerCertificate'],
            ],
        ];
    }

    private function cors($value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid([['path' => 'cors', 'message' => 'CORS configuration must be an object.']]);
        }
        $this->rejectUnknown($value, ['allowedOrigins', 'credentialsEnabled', 'allowedMethods'], 'cors.');
        $errors = [];
        $origins = $value['allowedOrigins'] ?? null;
        $normalized = [];
        if (!is_array($origins) || !array_is_list($origins)) {
            $errors[] = ['path' => 'cors.allowedOrigins', 'message' => 'Allowed origins must be an array.'];
        } else {
            foreach ($origins as $index => $origin) {
                try {
                    if (!is_string($origin)) throw new InvalidArgumentException();
                    $origin = AdminConfigurationRepository::normalizeOrigin($origin);
                    if (in_array($origin, $normalized, true)) throw new InvalidArgumentException();
                    $normalized[] = $origin;
                } catch (InvalidArgumentException $exception) {
                    $errors[] = ['path' => "cors.allowedOrigins.{$index}", 'message' => 'Origin must be a unique exact HTTP or HTTPS origin.'];
                }
            }
        }
        $credentials = $value['credentialsEnabled'] ?? null;
        if (!is_bool($credentials)) {
            $errors[] = ['path' => 'cors.credentialsEnabled', 'message' => 'Value must be boolean.'];
        }
        $methods = $value['allowedMethods'] ?? null;
        if (!is_array($methods) || !array_is_list($methods) || $methods === []) {
            $errors[] = ['path' => 'cors.allowedMethods', 'message' => 'Allowed methods must be a non-empty array.'];
            $methods = [];
        } else {
            $methods = array_values(array_unique(array_map(fn ($method) => strtoupper((string)$method), $methods)));
            if (array_diff($methods, AdminConfigurationRepository::ALLOWED_CORS_METHODS) !== []) {
                $errors[] = ['path' => 'cors.allowedMethods', 'message' => 'Unsupported CORS method.'];
            }
        }
        if ($errors !== []) $this->invalid($errors);
        return [
            'allowedOrigins' => $normalized,
            'credentialsEnabled' => $credentials,
            'allowedMethods' => $methods,
        ];
    }

    private function connectionStringValue($value, string $field, bool $required, array &$errors): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (($required && $value === '') || strlen($value) > 255
            || preg_match('/[;{}\r\n\x00]/', $value) === 1) {
            $errors[] = ['path' => 'database.' . $field, 'message' => 'Invalid database connection value.'];
        }
        return $value;
    }

    private function rejectUnknown(array $value, array $allowed, string $prefix = ''): void
    {
        $details = [];
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                $details[] = ['path' => $prefix . $key, 'message' => 'Unknown property.'];
            }
        }
        if ($details !== []) $this->invalid($details);
    }

    private function invalid(array $details): never
    {
        throw new ApiRequestException('Invalid admin request.', 'INVALID_ADMIN_REQUEST', $details);
    }
}
