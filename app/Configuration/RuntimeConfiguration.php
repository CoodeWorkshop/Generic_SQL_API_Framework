<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

final class RuntimeConfiguration
{
    public const AUTH_FILE = 'auth.json';
    public const INSTALLATION_FILE = 'installation.json';
    public const ADMIN_FILE = 'admin.json';

    public static function directory(): string
    {
        $override = getenv('GENERIC_RUNTIME_CONFIG_DIR');
        return $override !== false && trim($override) !== ''
            ? rtrim(trim($override), '/\\')
            : ROOT_PATH . '/config';
    }

    public static function path(string $file): string
    {
        if (!in_array($file, [self::AUTH_FILE, self::INSTALLATION_FILE, self::ADMIN_FILE], true)) {
            throw new InvalidArgumentException('Unsupported runtime configuration file.');
        }
        return self::directory() . DIRECTORY_SEPARATOR . $file;
    }

    public static function ensure(): array
    {
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Runtime configuration directory is unavailable.');
        }
        @chmod($directory, 0700);

        $lockPath = $directory . DIRECTORY_SEPARATOR . '.bootstrap.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) throw new RuntimeException('Runtime configuration bootstrap lock is unavailable.');
        @chmod($lockPath, 0600);

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Runtime configuration bootstrap lock could not be acquired.');
            }
            $created = [];
            foreach (self::defaults() as $file => $configuration) {
                $path = $directory . DIRECTORY_SEPARATOR . $file;
                if (is_file($path)) continue;
                JsonFileStore::save($path, $configuration);
                $created[] = $file;
            }
            return ['directory' => $directory, 'created' => $created];
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function authDefaults(): array
    {
        return ['version' => 2, 'users' => []];
    }

    public static function installationDefaults(): array
    {
        return [
            'version' => 1,
            'installationId' => bin2hex(random_bytes(32)),
            'initialized' => false,
        ];
    }

    public static function adminDefaults(): array
    {
        return [
            'version' => 2,
            'server' => [
                'apiPortMinimum' => 8000,
                'apiPortMaximum' => 8100,
                'adminPort' => 8090,
                'bindAddress' => '127.0.0.1',
            ],
            'features' => [
                'readData' => true,
                'writeData' => true,
                'pagination' => true,
                'sorting' => true,
                'metadata' => true,
            ],
            'cors' => [
                'allowedOrigins' => [
                    'http://127.0.0.1:5173',
                    'http://localhost:5173',
                    'http://127.0.0.1:5314',
                    'http://localhost:5314',
                    'http://127.0.0.1:5341',
                    'http://localhost:5341',
                ],
                'credentialsEnabled' => true,
                'allowedMethods' => ['POST', 'OPTIONS'],
            ],
            'authentication' => ['mode' => 'session'],
        ];
    }

    private static function defaults(): array
    {
        return [
            self::AUTH_FILE => self::authDefaults(),
            self::INSTALLATION_FILE => self::installationDefaults(),
            self::ADMIN_FILE => self::adminDefaults(),
        ];
    }
}
