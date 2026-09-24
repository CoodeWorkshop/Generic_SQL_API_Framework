<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

final class ApplicationBackupManager
{
    public const FORMAT_VERSION = 1;
    public const MANIFEST_FILE = 'manifest.json';

    private string $applicationRoot;
    private array $sources;
    private string $applicationVersion;

    public function __construct(?string $applicationRoot = null, ?array $sources = null, ?string $applicationVersion = null)
    {
        $this->applicationRoot = rtrim($applicationRoot ?? dirname(__DIR__, 2), '/\\');
        $this->sources = $sources ?? [
            'config/auth.json' => RuntimeConfiguration::path(RuntimeConfiguration::AUTH_FILE),
            'config/installation.json' => RuntimeConfiguration::path(RuntimeConfiguration::INSTALLATION_FILE),
            'config/admin.json' => RuntimeConfiguration::path(RuntimeConfiguration::ADMIN_FILE),
            'config/authorization.json' => RuntimeConfiguration::path(RuntimeConfiguration::AUTHORIZATION_FILE),
            'config/api-keys.json' => RuntimeConfiguration::path(RuntimeConfiguration::API_KEYS_FILE),
            'database/config/database.json' => $this->applicationRoot . '/database/config/database.json',
        ];
        $this->applicationVersion = $applicationVersion ?? $this->readApplicationVersion();
        $this->validateSourceMap();
    }

    public function create(string $bundlePath): array
    {
        $bundlePath = $this->absoluteTargetPath($bundlePath);
        $this->assertOutsideApplicationRoot($bundlePath);
        if (file_exists($bundlePath)) throw new RuntimeException('Backup destination already exists.');

        $parent = dirname($bundlePath);
        $this->ensureDirectory($parent);
        $temporaryPath = $parent . DIRECTORY_SEPARATOR . '.' . basename($bundlePath)
            . '.tmp.' . bin2hex(random_bytes(8));
        if (!@mkdir($temporaryPath, 0700)) throw new RuntimeException('Backup staging directory could not be created.');
        $complete = false;

        try {
            $files = [];
            foreach ($this->sources as $logicalPath => $sourcePath) {
                $value = $this->loadAndValidateSource($logicalPath, $sourcePath, true);
                $targetPath = $temporaryPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logicalPath);
                $this->ensureDirectory(dirname($targetPath));
                $this->writeJson($targetPath, $value);
                $size = filesize($targetPath);
                $checksum = hash_file('sha256', $targetPath);
                if (!is_int($size) || $size < 3 || !is_string($checksum)) {
                    throw new RuntimeException('Backup file integrity metadata could not be generated.');
                }
                $files[] = ['path' => $logicalPath, 'size' => $size, 'sha256' => $checksum];
            }

            $manifest = [
                'formatVersion' => self::FORMAT_VERSION,
                'createdAt' => gmdate(DATE_ATOM),
                'application' => ['name' => 'Generic SQL API Framework', 'version' => $this->applicationVersion],
                'files' => $files,
                'excluded' => [
                    'encryption_key', 'sessions', 'runtime_process_state', 'database_availability_state',
                    'rate_limit_state', 'logs', 'exports', 'uploads', 'sql_server_data',
                ],
            ];
            $this->writeJson($temporaryPath . DIRECTORY_SEPARATOR . self::MANIFEST_FILE, $manifest);
            $this->verify($temporaryPath, true, false);
            if (!@rename($temporaryPath, $bundlePath)) {
                throw new RuntimeException('Backup bundle could not be finalized atomically.');
            }
            $complete = true;
            return $manifest;
        } finally {
            if (!$complete) $this->removeDirectory($temporaryPath);
        }
    }

    public function verify(string $bundlePath, bool $requireEncryptionKey = true, bool $enforceExternalLocation = true): array
    {
        $bundlePath = $this->absoluteExistingPath($bundlePath);
        if ($enforceExternalLocation) $this->assertOutsideApplicationRoot($bundlePath);
        $manifestPath = $bundlePath . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        $manifest = $this->loadJsonFile($manifestPath, 'Backup manifest is missing or invalid.');
        $this->validateManifest($manifest);

        $manifestPaths = [];
        foreach ($manifest['files'] as $file) {
            $logicalPath = $file['path'];
            $manifestPaths[] = $logicalPath;
            $path = $bundlePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logicalPath);
            if (!is_file($path)) throw new RuntimeException("Backup file is missing: {$logicalPath}.");
            $size = filesize($path);
            $checksum = hash_file('sha256', $path);
            if ($size !== $file['size'] || !is_string($checksum) || !hash_equals($file['sha256'], $checksum)) {
                throw new RuntimeException("Backup checksum verification failed: {$logicalPath}.");
            }
            $value = $this->loadJsonFile($path, "Backup JSON is invalid: {$logicalPath}.");
            $this->validateConfiguration($logicalPath, $value, $requireEncryptionKey);
        }
        if ($manifestPaths !== array_keys($this->sources)) {
            throw new RuntimeException('Backup manifest file set is incomplete or out of order.');
        }
        return $manifest;
    }

    public function stageRestore(string $bundlePath, string $targetDirectory): array
    {
        $manifest = $this->verify($bundlePath, true);
        $bundlePath = $this->absoluteExistingPath($bundlePath);
        $targetDirectory = $this->absoluteTargetPath($targetDirectory);
        $this->assertOutsideApplicationRoot($targetDirectory);
        if (file_exists($targetDirectory)) throw new RuntimeException('Restore staging destination must not already exist.');
        $parent = dirname($targetDirectory);
        $this->ensureDirectory($parent);
        $temporaryPath = $parent . DIRECTORY_SEPARATOR . '.' . basename($targetDirectory)
            . '.tmp.' . bin2hex(random_bytes(8));
        if (!@mkdir($temporaryPath, 0700)) throw new RuntimeException('Restore staging directory could not be created.');
        $complete = false;
        try {
            foreach ($manifest['files'] as $file) {
                $logicalPath = $file['path'];
                $sourcePath = $bundlePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logicalPath);
                $targetPath = $temporaryPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logicalPath);
                $this->ensureDirectory(dirname($targetPath));
                $this->writeJson($targetPath, $this->loadJsonFile($sourcePath, 'Backup file could not be staged.'));
            }
            $this->writeJson($temporaryPath . DIRECTORY_SEPARATOR . self::MANIFEST_FILE, $manifest);
            if (!@rename($temporaryPath, $targetDirectory)) {
                throw new RuntimeException('Restore staging directory could not be finalized atomically.');
            }
            $complete = true;
            return ['ready' => true, 'path' => $targetDirectory, 'files' => count($manifest['files'])];
        } finally {
            if (!$complete) $this->removeDirectory($temporaryPath);
        }
    }

    private function loadAndValidateSource(string $logicalPath, string $sourcePath, bool $requireEncryptionKey): array
    {
        try {
            $value = JsonFileStore::load($sourcePath);
        } catch (Throwable $exception) {
            throw new RuntimeException("Required backup source is missing or invalid: {$logicalPath}.");
        }
        $this->validateConfiguration($logicalPath, $value, $requireEncryptionKey);
        return $value;
    }

    private function validateConfiguration(string $logicalPath, array $value, bool $requireEncryptionKey): void
    {
        $valid = match ($logicalPath) {
            'config/auth.json' => ($value['version'] ?? null) === 4 && is_array($value['users'] ?? null) && array_is_list($value['users']),
            'config/installation.json' => ($value['version'] ?? null) === 1
                && is_string($value['installationId'] ?? null) && is_bool($value['initialized'] ?? null),
            'config/admin.json' => ($value['version'] ?? null) === 5
                && is_array($value['server'] ?? null) && is_array($value['runtime'] ?? null),
            'config/authorization.json' => ($value['version'] ?? null) === 2
                && is_array($value['roles'] ?? null) && !array_is_list($value['roles']),
            'config/api-keys.json' => ($value['version'] ?? null) === 2
                && is_array($value['keys'] ?? null) && array_is_list($value['keys']),
            'database/config/database.json' => DatabaseConfigurationResolver::usesEncryption($value),
            default => false,
        };
        if (!$valid) throw new RuntimeException("Backup configuration schema is invalid: {$logicalPath}.");
        if ($logicalPath === 'database/config/database.json' && $requireEncryptionKey) {
            try {
                DatabaseConfigurationResolver::resolve($value);
            } catch (Throwable $exception) {
                throw new RuntimeException('Encrypted database configuration cannot be recovered with the available key.');
            }
        }
    }

    private function validateManifest(array $manifest): void
    {
        if (($manifest['formatVersion'] ?? null) !== self::FORMAT_VERSION
            || !is_string($manifest['createdAt'] ?? null) || strtotime($manifest['createdAt']) === false
            || !is_array($manifest['application'] ?? null)
            || ($manifest['application']['name'] ?? null) !== 'Generic SQL API Framework'
            || !is_string($manifest['application']['version'] ?? null)
            || !is_array($manifest['files'] ?? null) || !array_is_list($manifest['files'])
            || !is_array($manifest['excluded'] ?? null) || !array_is_list($manifest['excluded'])) {
            throw new RuntimeException('Backup manifest is invalid.');
        }
        foreach ($manifest['files'] as $file) {
            if (!is_array($file) || array_keys($file) !== ['path', 'size', 'sha256']
                || !is_string($file['path'] ?? null) || !array_key_exists($file['path'], $this->sources)
                || !is_int($file['size'] ?? null) || $file['size'] < 3
                || !is_string($file['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $file['sha256']) !== 1) {
                throw new RuntimeException('Backup manifest file metadata is invalid.');
            }
        }
    }

    private function validateSourceMap(): void
    {
        $expected = [
            'config/auth.json', 'config/installation.json', 'config/admin.json',
            'config/authorization.json', 'config/api-keys.json', 'database/config/database.json',
        ];
        if (array_keys($this->sources) !== $expected) throw new InvalidArgumentException('Unsupported backup source map.');
        foreach ($this->sources as $source) {
            if (!is_string($source) || trim($source) === '') throw new InvalidArgumentException('Invalid backup source path.');
        }
    }

    private function writeJson(string $path, array $value): void
    {
        try {
            $contents = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (Throwable $exception) {
            throw new RuntimeException('Backup JSON could not be encoded.');
        }
        $stream = @fopen($path, 'x+b');
        if ($stream === false) throw new RuntimeException('Backup file could not be created.');
        $complete = false;
        try {
            $restricted = @chmod($path, 0600);
            if (PHP_OS_FAMILY !== 'Windows') {
                clearstatcache(true, $path);
                if (!$restricted || (@fileperms($path) & 0777) !== 0600) {
                    throw new RuntimeException('Backup file permissions could not be restricted.');
                }
            }
            $length = strlen($contents);
            $written = 0;
            while ($written < $length) {
                $bytes = fwrite($stream, substr($contents, $written));
                if ($bytes === false || $bytes === 0) throw new RuntimeException('Backup file write failed.');
                $written += $bytes;
            }
            if (!fflush($stream)) throw new RuntimeException('Backup file flush failed.');
            $complete = true;
        } finally {
            fclose($stream);
            if (!$complete) @unlink($path);
        }
    }

    private function loadJsonFile(string $path, string $message): array
    {
        if (!is_file($path)) throw new RuntimeException($message);
        $contents = @file_get_contents($path);
        if ($contents === false) throw new RuntimeException($message);
        try { $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable $exception) { throw new RuntimeException($message); }
        if (!is_array($value) || array_is_list($value)) throw new RuntimeException($message);
        return $value;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            if (!@mkdir($path, 0700, true) && !is_dir($path)) {
                throw new RuntimeException('Backup directory could not be created.');
            }
            @chmod($path, 0700);
        }
    }

    private function absoluteExistingPath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) throw new RuntimeException('Backup directory was not found.');
        return rtrim($resolved, '/\\');
    }

    private function absoluteTargetPath(string $path): string
    {
        if (trim($path) === '' || str_contains($path, "\0")) throw new InvalidArgumentException('Backup path is invalid.');
        if (!$this->isAbsolutePath($path)) throw new InvalidArgumentException('Backup path must be absolute.');
        $parent = realpath(dirname($path));
        if ($parent === false) throw new RuntimeException('Backup parent directory was not found.');
        return rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . basename($path);
    }

    private function assertOutsideApplicationRoot(string $path): void
    {
        $root = realpath($this->applicationRoot);
        if ($root === false) throw new RuntimeException('Application root is unavailable.');
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/') . '/';
        if (str_starts_with(PHP_OS_FAMILY === 'Windows' ? strtolower($normalizedPath) : $normalizedPath,
            PHP_OS_FAMILY === 'Windows' ? strtolower($normalizedRoot) : $normalizedRoot)) {
            throw new RuntimeException('Backup and restore staging paths must be outside the application root.');
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function readApplicationVersion(): string
    {
        $path = $this->applicationRoot . '/config/app.php';
        if (!is_file($path)) return 'unknown';
        $configuration = require $path;
        return is_array($configuration) && is_string($configuration['version'] ?? null)
            ? $configuration['version'] : 'unknown';
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($directory);
    }
}
