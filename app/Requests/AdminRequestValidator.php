<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';
require_once __DIR__ . '/../Runtime/DatabaseAuthenticationSupport.php';

final class AdminRequestValidator
{
    private DatabaseAuthenticationSupport $databaseAuthentication;

    public function __construct(?DatabaseAuthenticationSupport $databaseAuthentication = null)
    {
        $this->databaseAuthentication = $databaseAuthentication ?? new DatabaseAuthenticationSupport();
    }

    private const SIMPLE_ACTIONS = [
        'admin.status',
        'admin.health',
        'admin.system.info',
        'admin.api.start',
        'admin.api.stop',
        'admin.api.restart',
        'admin.sqlParser.start',
        'admin.sqlParser.stop',
        'admin.sqlParser.restart',
        'admin.database.get',
        'admin.database.testCurrent',
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
        if ($action === 'admin.server.save') {
            $this->rejectUnknown($request, ['action', 'server']);
            return ['action' => $action, 'server' => $this->server($request['server'] ?? null)];
        }
        if ($action === 'admin.features.save') {
            $this->rejectUnknown($request, ['action', 'features']);
            return ['action' => $action, 'features' => $this->features($request['features'] ?? null)];
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
        throw new ApiRequestException('Invalid admin request.', 'INVALID_ADMIN_REQUEST');
    }

    private function server($value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid([['path' => 'server', 'message' => 'Server configuration must be an object.']]);
        }
        $this->rejectUnknown($value, ['apiPortMinimum', 'apiPortMaximum', 'parserPortMinimum', 'parserPortMaximum', 'adminPort', 'bindAddress'], 'server.');
        $errors = [];
        $normalized = [];
        foreach (['apiPortMinimum', 'apiPortMaximum', 'parserPortMinimum', 'parserPortMaximum', 'adminPort'] as $field) {
            $port = filter_var($value[$field] ?? null, FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                $errors[] = ['path' => 'server.' . $field, 'message' => 'Port must be between 1 and 65535.'];
            } else {
                $normalized[$field] = (int)$port;
            }
        }
        if (isset($normalized['apiPortMinimum'], $normalized['apiPortMaximum'])
            && $normalized['apiPortMinimum'] > $normalized['apiPortMaximum']) {
            $errors[] = ['path' => 'server.apiPortMaximum', 'message' => 'Maximum port must be greater than or equal to minimum port.'];
        }
        if (isset($normalized['parserPortMinimum'], $normalized['parserPortMaximum'])
            && $normalized['parserPortMinimum'] > $normalized['parserPortMaximum']) {
            $errors[] = ['path' => 'server.parserPortMaximum', 'message' => 'Maximum port must be greater than or equal to minimum port.'];
        }
        if (($value['bindAddress'] ?? null) !== '127.0.0.1') {
            $errors[] = ['path' => 'server.bindAddress', 'message' => 'Only the loopback bind address is supported.'];
        }
        if ($errors !== []) $this->invalid($errors);
        return [
            'apiPortMinimum' => $normalized['apiPortMinimum'],
            'apiPortMaximum' => $normalized['apiPortMaximum'],
            'parserPortMinimum' => $normalized['parserPortMinimum'],
            'parserPortMaximum' => $normalized['parserPortMaximum'],
            'adminPort' => $normalized['adminPort'],
            'bindAddress' => '127.0.0.1',
        ];
    }

    private function features($value): array
    {
        $keys = ['readData', 'writeData', 'pagination', 'sorting', 'metadata'];
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid([['path' => 'features', 'message' => 'Feature configuration must be an object.']]);
        }
        $this->rejectUnknown($value, $keys, 'features.');
        $errors = [];
        foreach ($keys as $key) {
            if (!is_bool($value[$key] ?? null)) {
                $errors[] = ['path' => 'features.' . $key, 'message' => 'Feature value must be boolean.'];
            }
        }
        if ($errors !== []) $this->invalid($errors);
        return array_combine($keys, array_map(fn (string $key): bool => $value[$key], $keys));
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
        try {
            $this->databaseAuthentication->validate($authentication);
        } catch (InvalidArgumentException $exception) {
            $errors[] = ['path' => 'database.authentication', 'message' => $exception->getMessage()];
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
