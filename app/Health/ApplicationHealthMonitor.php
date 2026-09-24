<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

final class ApplicationHealthMonitor
{
    private string $root;
    private string $configurationDirectory;
    private string $databasePath;
    private string $runtimeDirectory;
    private string $logDirectory;
    private string $sessionDirectory;
    private ?string $backupDirectory;
    private string $databaseCachePath;
    private int $databaseCacheTtl;
    private int $diskWarningBytes;
    private int $diskCriticalBytes;
    private $databaseTester;
    private $databaseAvailable;
    private $diskSpace;

    public function __construct(array $options = [])
    {
        $this->root = rtrim($options['root'] ?? dirname(__DIR__, 2), '/\\');
        $this->configurationDirectory = $options['configurationDirectory'] ?? RuntimeConfiguration::directory();
        $this->databasePath = $options['databasePath'] ?? $this->root . '/database/config/database.json';
        $this->runtimeDirectory = $options['runtimeDirectory'] ?? $this->root . '/runtime';
        $this->logDirectory = $options['logDirectory'] ?? $this->root . '/logs';
        $savePath = $options['sessionDirectory'] ?? session_save_path();
        if (is_string($savePath) && str_contains($savePath, ';')) {
            $parts = explode(';', $savePath);
            $savePath = (string)end($parts);
        }
        $this->sessionDirectory = $savePath !== '' ? $savePath : sys_get_temp_dir();
        $configuredBackup = $options['backupDirectory'] ?? getenv('GENERIC_BACKUP_DIR');
        $this->backupDirectory = is_string($configuredBackup) && trim($configuredBackup) !== ''
            ? rtrim(trim($configuredBackup), '/\\') : null;
        $this->databaseCachePath = $options['databaseCachePath']
            ?? $this->runtimeDirectory . '/health/database-health.json';
        $this->databaseCacheTtl = max(1, (int)($options['databaseCacheTtl'] ?? 15));
        $this->diskWarningBytes = max(0, (int)($options['diskWarningBytes'] ?? 1073741824));
        $this->diskCriticalBytes = max(0, (int)($options['diskCriticalBytes'] ?? 268435456));
        $this->databaseTester = $options['databaseTester'] ?? null;
        $this->databaseAvailable = $options['databaseAvailable'] ?? null;
        $this->diskSpace = $options['diskSpace'] ?? static fn (string $path) => @disk_free_space($path);
    }

    public function liveness(string $service = 'api', ?int $port = null, ?string $startedAt = null): array
    {
        $started = is_string($startedAt) ? strtotime($startedAt) : false;
        return [
            'status' => 'healthy',
            'service' => $service,
            'version' => $this->applicationVersion(),
            'port' => $port,
            'startedAt' => $startedAt,
            'uptimeSeconds' => $started === false ? null : max(0, time() - $started),
        ];
    }

    public function readiness(): array
    {
        $configuration = $this->configurationHealth();
        $runtime = $this->directoryHealth($this->runtimeDirectory, true);
        $database = $this->databaseReadiness();
        $ready = $configuration['status'] === 'healthy'
            && $runtime['status'] === 'healthy'
            && $database['status'] === 'healthy';
        return [
            'status' => $ready ? 'healthy' : 'unhealthy',
            'checks' => [
                'configuration' => $this->publicCheck($configuration),
                'runtime' => $this->publicCheck($runtime),
                'database' => $this->publicCheck($database),
            ],
        ];
    }

    public function detailed(array $processes): array
    {
        $checks = [
            'application' => ['status' => 'healthy', 'category' => 'responding', 'version' => $this->applicationVersion()],
            'configuration' => $this->configurationHealth(),
            'database' => $this->databaseHealth(),
            'filesystem' => $this->filesystemHealth(),
            'logging' => $this->loggingHealth(),
            'sessions' => $this->sessionHealth(),
            'encryption' => $this->encryptionHealth(),
            'backup' => $this->backupHealth(),
            'processes' => ['status' => $this->processAggregate($processes), 'services' => $processes],
        ];
        $statuses = array_column($checks, 'status');
        $status = in_array('unhealthy', $statuses, true) ? 'unhealthy'
            : (in_array('degraded', $statuses, true) ? 'degraded' : 'healthy');
        return ['status' => $status, 'checks' => $checks];
    }

    private function configurationHealth(): array
    {
        $versions = ['auth.json' => 4, 'installation.json' => 1, 'admin.json' => 5,
            'authorization.json' => 2, 'api-keys.json' => 2, 'database-state.json' => 1];
        foreach ($versions as $file => $version) {
            $path = $this->configurationDirectory . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) return ['status' => 'unhealthy', 'category' => 'configuration_missing'];
            try { $value = JsonFileStore::load($path); }
            catch (Throwable $exception) { return ['status' => 'unhealthy', 'category' => 'configuration_invalid']; }
            if (($value['version'] ?? null) !== $version || !$this->configurationShapeIsValid($file, $value)) {
                return ['status' => 'unhealthy', 'category' => 'configuration_invalid'];
            }
        }
        return ['status' => 'healthy', 'category' => 'configuration_valid'];
    }

    private function configurationShapeIsValid(string $file, array $value): bool
    {
        return match ($file) {
            'auth.json' => is_array($value['users'] ?? null),
            'installation.json' => is_string($value['installationId'] ?? null)
                && is_bool($value['initialized'] ?? null),
            'admin.json' => is_array($value['server'] ?? null) && is_array($value['cors'] ?? null)
                && is_array($value['authentication'] ?? null) && is_array($value['runtime'] ?? null),
            'authorization.json' => is_array($value['roles'] ?? null),
            'api-keys.json' => is_array($value['keys'] ?? null),
            'database-state.json' => is_bool($value['available'] ?? null),
            default => false,
        };
    }

    private function databaseReadiness(): array
    {
        try {
            $available = is_callable($this->databaseAvailable)
                ? (bool)($this->databaseAvailable)()
                : $this->storedDatabaseAvailability();
            if (!$available) {
                return ['status' => 'unhealthy', 'category' => 'database_disconnected'];
            }
            DatabaseConfigurationResolver::load($this->databasePath);
            return ['status' => 'healthy', 'category' => 'database_available'];
        } catch (Throwable $exception) {
            return ['status' => 'unhealthy', 'category' => $this->databaseConfigurationCategory()];
        }
    }

    private function storedDatabaseAvailability(): bool
    {
        $state = JsonFileStore::load($this->configurationDirectory . '/database-state.json');
        return ($state['version'] ?? null) === 1 && ($state['available'] ?? null) === true;
    }

    private function databaseHealth(): array
    {
        $ready = $this->databaseReadiness();
        if ($ready['status'] !== 'healthy' || !is_callable($this->databaseTester)) return $ready;
        $fingerprint = is_file($this->databasePath) ? hash_file('sha256', $this->databasePath) : false;
        $cached = is_string($fingerprint) ? $this->readDatabaseCache($fingerprint) : null;
        if ($cached !== null) return $cached + ['cached' => true];
        $started = microtime(true);
        try {
            ($this->databaseTester)(DatabaseConfigurationResolver::load($this->databasePath));
            $result = ['status' => 'healthy', 'category' => 'connected'];
        } catch (Throwable $exception) {
            $result = ['status' => 'unhealthy', 'category' => $this->databaseFailureCategory($exception)];
        }
        $result += ['cached' => false, 'checkedAt' => gmdate(DATE_ATOM),
            'durationMs' => round((microtime(true) - $started) * 1000, 2)];
        if (is_string($fingerprint)) $this->writeDatabaseCache($result + ['configurationFingerprint' => $fingerprint]);
        return $result;
    }

    private function databaseConfigurationCategory(): string
    {
        if (!is_file($this->databasePath)) return 'configuration_missing';
        if (!DatabaseConfigurationResolver::encryptionKeyIsAvailable()) return 'encryption_key_missing';
        try { DatabaseConfigurationResolver::load($this->databasePath); }
        catch (Throwable $exception) { return 'configuration_invalid'; }
        return 'database_unavailable';
    }

    private function databaseFailureCategory(Throwable $exception): string
    {
        $message = strtoupper($exception->getMessage());
        return str_contains($message, '28000') || str_contains($message, 'LOGIN FAILED')
            ? 'authentication_failure' : 'database_unavailable';
    }

    private function encryptionHealth(): array
    {
        if (!DatabaseConfigurationResolver::encryptionKeyIsAvailable()) {
            return ['status' => 'unhealthy', 'category' => 'missing'];
        }
        try { DatabaseConfigurationResolver::load($this->databasePath); }
        catch (Throwable $exception) { return ['status' => 'unhealthy', 'category' => 'invalid']; }
        return ['status' => 'healthy', 'category' => 'configured'];
    }

    private function filesystemHealth(): array
    {
        $directories = [
            'configuration' => $this->directoryHealth($this->configurationDirectory, true),
            'runtime' => $this->directoryHealth($this->runtimeDirectory, true),
            'temporary' => $this->directoryHealth(sys_get_temp_dir(), true),
        ];
        $statuses = array_column($directories, 'status');
        return ['status' => in_array('unhealthy', $statuses, true) ? 'unhealthy'
            : (in_array('degraded', $statuses, true) ? 'degraded' : 'healthy'), 'directories' => $directories];
    }

    private function loggingHealth(): array
    {
        $check = $this->directoryHealth($this->logDirectory, true);
        return ['status' => $check['status'] === 'healthy' ? 'healthy' : 'degraded',
            'category' => $check['status'] === 'healthy' ? 'operational' : 'unavailable'];
    }

    private function sessionHealth(): array
    {
        $check = $this->directoryHealth($this->sessionDirectory, true);
        return ['status' => $check['status'], 'category' => $check['status'] === 'healthy'
            ? 'operational' : 'unavailable'];
    }

    private function backupHealth(): array
    {
        if ($this->backupDirectory === null) return ['status' => 'degraded', 'category' => 'not_configured'];
        $check = $this->directoryHealth($this->backupDirectory, true);
        return ['status' => $check['status'] === 'healthy' ? 'healthy' : 'degraded',
            'category' => $check['status'] === 'healthy' ? 'capable' : 'unavailable'];
    }

    private function directoryHealth(string $path, bool $writable): array
    {
        if (!is_dir($path)) return ['status' => 'unhealthy', 'category' => 'missing'];
        if (!is_readable($path) || ($writable && !is_writable($path))) {
            return ['status' => 'unhealthy', 'category' => 'unavailable'];
        }
        $free = ($this->diskSpace)($path);
        if (is_int($free) || is_float($free)) {
            if ($this->diskCriticalBytes > 0 && $free < $this->diskCriticalBytes) {
                return ['status' => 'unhealthy', 'category' => 'disk_critical'];
            }
            if ($this->diskWarningBytes > 0 && $free < $this->diskWarningBytes) {
                return ['status' => 'degraded', 'category' => 'disk_warning'];
            }
        }
        return ['status' => 'healthy', 'category' => 'available'];
    }

    private function processAggregate(array $processes): string
    {
        foreach ($processes as $process) {
            if (($process['status'] ?? null) === 'unresponsive') return 'degraded';
        }
        return 'healthy';
    }

    private function publicCheck(array $check): array
    {
        return ['status' => $check['status'], 'category' => $check['category'] ?? 'unknown'];
    }

    private function readDatabaseCache(string $fingerprint): ?array
    {
        if (!is_file($this->databaseCachePath)) return null;
        try { $value = JsonFileStore::load($this->databaseCachePath); }
        catch (Throwable $exception) { return null; }
        $checked = strtotime((string)($value['checkedAt'] ?? ''));
        if ($checked === false || time() - $checked > $this->databaseCacheTtl
            || !hash_equals($fingerprint, (string)($value['configurationFingerprint'] ?? ''))) return null;
        unset($value['configurationFingerprint']);
        return isset($value['status'], $value['category']) ? $value : null;
    }

    private function writeDatabaseCache(array $value): void
    {
        $directory = dirname($this->databaseCachePath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
        try { JsonFileStore::save($this->databaseCachePath, $value); } catch (Throwable $exception) {}
    }

    private function applicationVersion(): string
    {
        $config = require $this->root . '/config/app.php';
        return (string)($config['version'] ?? 'unknown');
    }
}
