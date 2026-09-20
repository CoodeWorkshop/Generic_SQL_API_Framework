<?php

require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../Security/ApiKeyAuthenticator.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';
require_once __DIR__ . '/../Repositories/InstallationRepository.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Runtime/ApiProcessManager.php';
require_once __DIR__ . '/../Runtime/RuntimeDetector.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';

final class AdminService
{
    private AdminConfigurationRepository $configuration;
    private string $databasePath;
    private $connectionTester;
    private ApiProcessManager $processManager;
    private RuntimeDetector $runtimeDetector;

    public function __construct(
        ?AdminConfigurationRepository $configuration = null,
        ?string $databasePath = null,
        ?callable $connectionTester = null,
        ?ApiProcessManager $processManager = null,
        ?RuntimeDetector $runtimeDetector = null
    ) {
        $this->configuration = $configuration ?? new AdminConfigurationRepository();
        $this->databasePath = $databasePath
            ?? dirname(__DIR__, 2) . '/database/config/database.json';
        $this->connectionTester = $connectionTester ?? function (array $database): void {
            $driver = new SqlServerDriver($database);
            try {
                $driver->connect();
            } finally {
                $driver->disconnect();
            }
        };
        $this->processManager = $processManager ?? new ApiProcessManager($this->configuration);
        $this->runtimeDetector = $runtimeDetector ?? new RuntimeDetector();
    }

    public function status(): array
    {
        $settings = $this->configuration->load();
        $database = $this->databaseHealth();
        $api = $this->processManager->status();
        return [
            'adminConsole' => [
                'running' => true,
                'healthy' => true,
                'status' => 'running',
                'port' => (int)($_SERVER['SERVER_PORT'] ?? $settings['server']['adminPort']),
                'startedAt' => getenv('GENERIC_ADMIN_STARTED_AT') ?: null,
            ],
            'api' => $api,
            'database' => $database,
            'phpRuntime' => [
                'running' => true,
                'healthy' => true,
                'status' => 'healthy',
                'phpVersion' => PHP_VERSION,
                'odbcAvailable' => extension_loaded('odbc'),
                'opensslAvailable' => extension_loaded('openssl'),
            ],
        ];
    }

    public function systemInformation(): array
    {
        $settings = $this->configuration->load();
        $runtime = $this->runtimeDetector->information();
        $installation = (new InstallationRepository())->load();
        return [
            ...$runtime,
            'apiPort' => $this->processManager->status()['port'],
            'adminPort' => (int)($_SERVER['SERVER_PORT'] ?? $settings['server']['adminPort']),
            'apiPortMinimum' => $settings['server']['apiPortMinimum'],
            'apiPortMaximum' => $settings['server']['apiPortMaximum'],
            'bindAddress' => $settings['server']['bindAddress'],
            'installationVersion' => $installation['version'],
            'installationInitialized' => $installation['initialized'],
        ];
    }

    public function databaseConfiguration(): array
    {
        if (!is_file($this->databasePath)) {
            return [
                'configured' => false,
                'encrypted' => false,
                'provider' => 'sqlserver',
                'driver' => 'auto',
                'server' => '',
                'port' => '1433',
                'database' => '',
                'authentication' => 'sql',
                'username' => '',
                'passwordConfigured' => false,
                'encrypt' => true,
                'trustServerCertificate' => false,
                'availableDrivers' => array_merge(['auto'], SqlServerDriver::supportedDrivers()),
            ];
        }
        try {
            $stored = DatabaseConfigurationResolver::readStored($this->databasePath);
            $database = DatabaseConfigurationResolver::resolve($stored);
        } catch (Throwable $exception) {
            throw new ApiRequestException(
                'Database configuration is unavailable.',
                'DATABASE_CONFIGURATION_UNAVAILABLE',
                [],
                503
            );
        }
        return [
            'configured' => true,
            'encrypted' => DatabaseConfigurationResolver::usesEncryption($stored),
            'provider' => $database['provider'],
            'driver' => $database['driver'] ?? 'auto',
            'server' => $database['server'] ?? '',
            'port' => isset($database['port']) ? (string)$database['port'] : '',
            'database' => $database['database'] ?? '',
            'authentication' => $database['authentication'] ?? 'sql',
            'username' => $database['username'] ?? '',
            'passwordConfigured' => (string)($database['password'] ?? '') !== '',
            'encrypt' => ($database['options']['encrypt'] ?? true) === true,
            'trustServerCertificate' => ($database['options']['trustServerCertificate'] ?? false) === true,
            'availableDrivers' => array_merge(['auto'], SqlServerDriver::supportedDrivers()),
        ];
    }

    public function testDatabase(array $database): array
    {
        $resolved = $this->withExistingPassword($database);
        try {
            ($this->connectionTester)($resolved);
        } catch (Throwable $exception) {
            throw new ApiRequestException(
                'Database connection failed.',
                'DATABASE_CONNECTION_FAILED',
                [],
                422
            );
        }
        return ['connected' => true];
    }

    public function saveDatabase(array $database): array
    {
        $resolved = $this->withExistingPassword($database);
        if ($resolved['authentication'] === 'sql' && $resolved['password'] === '') {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'database.password', 'message' => 'Password is required for SQL authentication.']]
            );
        }
        try {
            $encrypted = (new DatabaseCredentialEncryption())->encryptConfiguration($resolved);
            if (DatabaseConfigurationResolver::resolve($encrypted) !== $resolved) {
                throw new RuntimeException('Encrypted database configuration verification failed.');
            }
            JsonFileStore::save($this->databasePath, $encrypted);
        } catch (DatabaseCredentialException $exception) {
            throw new ApiRequestException(
                'Database encryption is unavailable.',
                'DATABASE_ENCRYPTION_UNAVAILABLE',
                [],
                503
            );
        } catch (Throwable $exception) {
            throw new ApiRequestException(
                'Unable to save database configuration.',
                'DATABASE_CONFIGURATION_SAVE_FAILED',
                [],
                500
            );
        }
        return ['configured' => true, 'encrypted' => true, 'passwordConfigured' => $resolved['password'] !== ''];
    }

    public function settings(): array
    {
        $settings = $this->configuration->load();
        return [
            'server' => $settings['server'],
            'features' => $settings['features'],
            'cors' => $settings['cors'],
            'authentication' => [
                'mode' => $settings['authentication']['mode'],
                'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured(),
            ],
            'security' => [
                'csrfEnabled' => true,
                'session' => SecurityConfiguration::sessionOptions(),
            ],
            'advanced' => [
                'queryTimeoutSeconds' => $this->runtimeDetector->information()['queryTimeoutSeconds'],
                'debugMode' => $this->runtimeDetector->information()['debugMode'],
                'logging' => 'server-managed',
            ],
        ];
    }

    public function saveServer(array $server): array
    {
        $runtime = $this->processManager->status();
        if (($runtime['running'] ?? false) === true && ($runtime['port'] ?? null) === $server['adminPort']) {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'server.adminPort', 'message' => 'Admin port conflicts with the running API port.']]
            );
        }
        return $this->configuration->update(function (array &$settings) use ($server): array {
            $settings['server'] = $server;
            return ['server' => $server, 'restartRequired' => true];
        });
    }

    public function saveFeatures(array $features): array
    {
        return $this->configuration->update(function (array &$settings) use ($features): array {
            $settings['features'] = $features;
            return ['features' => $features, 'restartRequired' => false];
        });
    }

    public function saveCors(array $cors): array
    {
        return $this->configuration->update(function (array &$settings) use ($cors): array {
            $settings['cors'] = $cors;
            return $cors;
        });
    }

    public function saveAuthentication(string $mode): array
    {
        return $this->configuration->update(function (array &$settings) use ($mode): array {
            $settings['authentication']['mode'] = $mode;
            return [
                'mode' => $mode,
                'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured(),
            ];
        });
    }

    public function controlApi(string $operation): array
    {
        try {
            if ($operation === 'start') return $this->processManager->start();
            if ($operation === 'stop') return $this->processManager->stop();
            return $this->processManager->restart();
        } catch (RuntimeException $exception) {
            throw new ApiRequestException($exception->getMessage(), 'API_PROCESS_OPERATION_FAILED', [], 409);
        }
    }

    private function databaseStatus(): array
    {
        if (!is_file($this->databasePath)) {
            return ['configured' => false, 'encrypted' => false, 'readable' => false];
        }
        try {
            $stored = DatabaseConfigurationResolver::readStored($this->databasePath);
            DatabaseConfigurationResolver::resolve($stored);
            return [
                'configured' => true,
                'encrypted' => DatabaseConfigurationResolver::usesEncryption($stored),
                'readable' => true,
            ];
        } catch (Throwable $exception) {
            return [
                'configured' => true,
                'encrypted' => $this->storedDatabaseIsEncrypted(),
                'readable' => false,
            ];
        }
    }

    private function databaseHealth(): array
    {
        $status = $this->databaseStatus();
        if (!$status['readable']) {
            return [...$status, 'connected' => false, 'healthy' => false, 'status' => 'not configured'];
        }
        try {
            ($this->connectionTester)(DatabaseConfigurationResolver::load($this->databasePath));
            return [...$status, 'connected' => true, 'healthy' => true, 'status' => 'connected'];
        } catch (Throwable $exception) {
            return [...$status, 'connected' => false, 'healthy' => false, 'status' => 'connection failed'];
        }
    }

    private function withExistingPassword(array $database): array
    {
        if ($database['password'] !== null && $database['password'] !== '') {
            return $database;
        }
        $database['password'] = '';
        if (!is_file($this->databasePath)) return $database;
        try {
            $existing = DatabaseConfigurationResolver::load($this->databasePath);
            $database['password'] = (string)($existing['password'] ?? '');
        } catch (Throwable $exception) {
            // Saving a replacement password remains possible when an old
            // encrypted file cannot be opened. Empty passwords fail validation.
        }
        return $database;
    }

    private function storedDatabaseIsEncrypted(): bool
    {
        try {
            return DatabaseConfigurationResolver::usesEncryption(
                DatabaseConfigurationResolver::readStored($this->databasePath)
            );
        } catch (Throwable $exception) {
            return false;
        }
    }
}
