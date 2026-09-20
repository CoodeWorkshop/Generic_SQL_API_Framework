<?php

require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../Security/ApiKeyAuthenticator.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';
require_once __DIR__ . '/../../sqlparser/src/SqlGenerator.php';

final class AdminService
{
    private AdminConfigurationRepository $configuration;
    private string $databasePath;
    private $connectionTester;

    public function __construct(
        ?AdminConfigurationRepository $configuration = null,
        ?string $databasePath = null,
        ?callable $connectionTester = null
    ) {
        $this->configuration = $configuration ?? new AdminConfigurationRepository();
        $this->databasePath = $databasePath
            ?? dirname(__DIR__, 2) . '/database/config/database.json';
        $this->connectionTester = $connectionTester ?? function (array $database): void {
            $driver = new SqlServerDriver($database);
            $driver->connect();
            $driver->disconnect();
        };
    }

    public function status(): array
    {
        $settings = $this->configuration->load();
        $database = $this->databaseStatus();
        return [
            'database' => $database,
            'encryption' => [
                'enabled' => $database['encrypted'],
                'keyConfigured' => DatabaseConfigurationResolver::encryptionKeyIsAvailable(),
                'algorithm' => DatabaseCredentialEncryption::ALGORITHM,
            ],
            'cors' => [
                'configured' => $settings['cors']['allowedOrigins'] !== [],
                'originCount' => count($settings['cors']['allowedOrigins']),
                'credentialsEnabled' => $settings['cors']['credentialsEnabled'],
            ],
            'authentication' => [
                'configured' => true,
                'mode' => $settings['authentication']['mode'],
                'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured(),
            ],
            'api' => ['running' => true],
            'sqlParser' => ['available' => class_exists(SqlGenerator::class)],
            'system' => [
                'phpVersion' => PHP_VERSION,
                'odbcAvailable' => extension_loaded('odbc'),
                'pdoOdbcAvailable' => extension_loaded('pdo_odbc'),
                'opensslAvailable' => extension_loaded('openssl'),
                'adminBinding' => '127.0.0.1',
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
            'cors' => $settings['cors'],
            'authentication' => [
                'mode' => $settings['authentication']['mode'],
                'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured(),
            ],
        ];
    }

    public function saveCors(array $cors): array
    {
        $settings = $this->configuration->load();
        $settings['cors'] = $cors;
        $this->configuration->save($settings);
        return $cors;
    }

    public function saveAuthentication(string $mode): array
    {
        $settings = $this->configuration->load();
        $settings['authentication']['mode'] = $mode;
        $this->configuration->save($settings);
        return [
            'mode' => $mode,
            'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured(),
        ];
    }

    public function convertSql(string $sql): array
    {
        // SqlGenerator performs lexical analysis, parsing, capability analysis,
        // mapping, and contract validation only. It has no database dependency.
        return (new SqlGenerator())->generate($sql);
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
