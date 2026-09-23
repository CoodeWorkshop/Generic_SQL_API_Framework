<?php

require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../Security/ApiKeyAuthenticator.php';
require_once __DIR__ . '/ApiKeyService.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';
require_once __DIR__ . '/../Repositories/InstallationRepository.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Runtime/ApiProcessManager.php';
require_once __DIR__ . '/../Runtime/SqlParserProcessManager.php';
require_once __DIR__ . '/../Runtime/DatabaseAuthenticationSupport.php';
require_once __DIR__ . '/../Runtime/RuntimeDetector.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';

final class AdminService
{
    private AdminConfigurationRepository $configuration;
    private string $databasePath;
    private $connectionTester;
    private ApiProcessManager $processManager;
    private SqlParserProcessManager $parserProcessManager;
    private RuntimeDetector $runtimeDetector;
    private DatabaseAuthenticationSupport $databaseAuthentication;
    private DatabaseAvailabilityManager $databaseAvailability;

    public function __construct(
        ?AdminConfigurationRepository $configuration = null,
        ?string $databasePath = null,
        ?callable $connectionTester = null,
        ?ApiProcessManager $processManager = null,
        ?RuntimeDetector $runtimeDetector = null,
        ?SqlParserProcessManager $parserProcessManager = null,
        ?DatabaseAuthenticationSupport $databaseAuthentication = null,
        ?DatabaseAvailabilityManager $databaseAvailability = null
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
        $this->parserProcessManager = $parserProcessManager ?? new SqlParserProcessManager($this->configuration);
        $this->databaseAuthentication = $databaseAuthentication ?? new DatabaseAuthenticationSupport();
        $this->databaseAvailability = $databaseAvailability ?? new DatabaseAvailabilityManager();
    }

    public function status(): array
    {
        $databaseAvailable = $this->databaseAvailability->available();
        $database = $databaseAvailable
            ? $this->databaseHealth()
            : [...$this->databaseStatus(), 'connected' => false, 'healthy' => false, 'status' => 'disconnected'];
        $database['available'] = $databaseAvailable;
        $api = $this->processManager->status();
        $parser = $this->parserProcessManager->status();
        return [
            'adminConsole' => [
                'running' => true,
                'healthy' => true,
                'status' => 'running',
                'pid' => getmypid(),
                'port' => isset($_SERVER['SERVER_PORT']) && filter_var($_SERVER['SERVER_PORT'], FILTER_VALIDATE_INT) !== false
                    ? (int)$_SERVER['SERVER_PORT']
                    : null,
                'startedAt' => getenv('GENERIC_ADMIN_STARTED_AT') ?: null,
            ],
            'api' => $api,
            'sqlParser' => $parser,
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
        $runtime = $this->runtimeDetector->information();
        $installation = (new InstallationRepository())->load();
        $api = $this->processManager->status();
        $parser = $this->parserProcessManager->status();
        $databaseAvailable = $this->databaseAvailability->available();
        $database = $databaseAvailable ? $this->databaseHealth() : ['status' => 'disconnected'];
        return [
            'application' => 'Generic SQL API Framework',
            'status' => 'running',
            'platform' => $runtime['operatingSystem'] . ' ' . $runtime['architecture'],
            'phpRuntime' => $runtime['phpRuntime'] . ' · PHP ' . $runtime['phpVersion'],
            'configurationStatus' => $installation['initialized'] ? 'initialized' : 'setup required',
            'databaseStatus' => $database['status'] ?? 'unknown',
            'services' => [
                'adminConsole' => 'running',
                'api' => $api['status'],
                'sqlParser' => $parser['status'],
            ],
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
                'availableAuthenticationModes' => $this->databaseAuthentication->modes(),
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
            'availableAuthenticationModes' => $this->databaseAuthentication->modes(),
        ];
    }

    public function testDatabase(array $database): array
    {
        $resolved = $this->withExistingPassword($database);
        $this->validateDatabaseAuthentication($resolved);
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

    public function testCurrentDatabase(): array
    {
        try {
            $database = DatabaseConfigurationResolver::load($this->databasePath);
        } catch (Throwable $exception) {
            throw new ApiRequestException(
                'Database configuration is unavailable.',
                'DATABASE_CONFIGURATION_UNAVAILABLE',
                [],
                503
            );
        }
        $this->validateDatabaseAuthentication($database);
        try {
            ($this->connectionTester)($database);
        } catch (Throwable $exception) {
            throw new ApiRequestException('Database connection failed.', 'DATABASE_CONNECTION_FAILED', [], 422);
        }
        return ['connected' => true];
    }

    public function saveDatabase(array $database): array
    {
        $resolved = $this->withExistingPassword($database);
        $this->validateDatabaseAuthentication($resolved);
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
            'cors' => $settings['cors'],
            'authentication' => [
                'mode' => $settings['authentication']['mode'],
                'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured() || (new ApiKeyService())->configured(),
            ],
            'runtime' => $settings['runtime'],
            'security' => [
                'csrfEnabled' => true,
                'session' => SecurityConfiguration::sessionOptions(),
            ],
            'advanced' => [
                'debugMode' => $this->runtimeDetector->information()['debugMode'],
                'logging' => 'server-managed',
            ],
        ];
    }

    public function saveServer(array $server): array
    {
        $currentServer = $this->configuration->load()['server'];
        $runtime = $this->processManager->status();
        $parserRuntime = $this->parserProcessManager->status();
        if (($runtime['running'] ?? false) === true && ($runtime['port'] ?? null) === $server['adminPort']) {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'server.adminPort', 'message' => 'Admin port conflicts with the running API port.']]
            );
        }
        if (($parserRuntime['running'] ?? false) === true && ($parserRuntime['port'] ?? null) === $server['adminPort']) {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'server.adminPort', 'message' => 'Admin port conflicts with the running SQL Parser port.']]
            );
        }
        $apiRestartRequired = $server['apiPortMinimum'] !== $currentServer['apiPortMinimum']
            || $server['apiPortMaximum'] !== $currentServer['apiPortMaximum'];
        $parserRestartRequired = $server['parserPortMinimum'] !== $currentServer['parserPortMinimum']
            || $server['parserPortMaximum'] !== $currentServer['parserPortMaximum'];
        $adminRestartRequired = $server['adminPort'] !== $currentServer['adminPort'];
        return $this->configuration->update(function (array &$settings) use (
            $server,
            $apiRestartRequired,
            $parserRestartRequired,
            $adminRestartRequired
        ): array {
            $settings['server'] = $server;
            return [
                'server' => $server,
                'apiRestartRequired' => $apiRestartRequired,
                'parserRestartRequired' => $parserRestartRequired,
                'adminRestartRequired' => $adminRestartRequired,
            ];
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
                'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured() || (new ApiKeyService())->configured(),
            ];
        });
    }

    public function saveRuntime(array $runtime): array
    {
        try {
            return $this->configuration->update(function (array &$settings) use ($runtime): array {
                $settings['runtime'] = $runtime;
                return $runtime;
            });
        } catch (Throwable $exception) {
            throw new ApiRequestException(
                'Unable to save runtime configuration.',
                'RUNTIME_CONFIGURATION_SAVE_FAILED',
                [],
                500
            );
        }
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

    public function controlSqlParser(string $operation): array
    {
        try {
            if ($operation === 'start') return $this->parserProcessManager->start();
            if ($operation === 'stop') return $this->parserProcessManager->stop();
            return $this->parserProcessManager->restart();
        } catch (RuntimeException $exception) {
            throw new ApiRequestException($exception->getMessage(), 'SQL_PARSER_PROCESS_OPERATION_FAILED', [], 409);
        }
    }

    public function controlDatabase(string $operation): array
    {
        if ($operation === 'disconnect') return $this->databaseAvailability->setAvailable(false);
        if ($operation === 'restart') $this->databaseAvailability->setAvailable(false);
        try { $this->testCurrentDatabase(); return $this->databaseAvailability->setAvailable(true); }
        catch (Throwable $exception) { $this->databaseAvailability->setAvailable(false); throw $exception; }
    }

    private function databaseStatus(): array
    {
        if (!is_file($this->databasePath)) {
            return ['configured' => false, 'encrypted' => false, 'readable' => false];
        }
        try {
            $stored = DatabaseConfigurationResolver::readStored($this->databasePath);
            $resolved = DatabaseConfigurationResolver::resolve($stored);
            $this->databaseAuthentication->validate((string)($resolved['authentication'] ?? ''));
            return [
                'configured' => true,
                'encrypted' => DatabaseConfigurationResolver::usesEncryption($stored),
                'readable' => true,
                'server' => (string)($resolved['server'] ?? ''),
                'port' => isset($resolved['port']) && $resolved['port'] !== '' ? (string)$resolved['port'] : null,
                'database' => (string)($resolved['database'] ?? ''),
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

    private function validateDatabaseAuthentication(array $database): void
    {
        try {
            $this->databaseAuthentication->validate((string)($database['authentication'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'database.authentication', 'message' => $exception->getMessage()]]
            );
        }
    }
}
