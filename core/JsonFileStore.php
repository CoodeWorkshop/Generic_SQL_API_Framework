<?php

final class JsonFileStore
{
    public static function load(string $path): array
    {
        return self::withIoLock($path, LOCK_SH, function () use ($path): array {
            if (!is_file($path)) {
                throw new RuntimeException('Configuration file was not found.');
            }

            $contents = @file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Configuration file could not be read.');
            }

            try {
                $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new RuntimeException('Configuration file contains invalid JSON.');
            }

            if (!is_array($value) || array_is_list($value)) {
                throw new RuntimeException('Configuration file must contain a JSON object.');
            }

            return $value;
        });
    }

    public static function save(string $path, array $value): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new RuntimeException('Configuration directory is unavailable.');
        }

        try {
            $contents = json_encode(
                $value,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
        } catch (Throwable $exception) {
            throw new RuntimeException('Configuration could not be encoded.');
        }

        self::withIoLock($path, LOCK_EX, function () use ($path, $contents): void {
            $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));
            try {
                if (@file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
                    throw new RuntimeException('Configuration file could not be written.');
                }
                @chmod($temporaryPath, 0600);

                if (!@rename($temporaryPath, $path)) {
                    // Some Windows filesystems cannot rename over an existing
                    // file. Readers share the I/O lock, so the fallback cannot
                    // expose a truncated or partially written JSON document.
                    if (@file_put_contents($path, $contents, LOCK_EX) === false) {
                        throw new RuntimeException('Configuration file could not be replaced.');
                    }
                    @unlink($temporaryPath);
                }
                @chmod($path, 0600);
            } finally {
                if (is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
        });
    }

    private static function withIoLock(string $path, int $operation, callable $callback)
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new RuntimeException('Configuration directory is unavailable.');
        }
        $lockPath = $path . '.io.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Configuration storage lock is unavailable.');
        }
        @chmod($lockPath, 0600);
        try {
            if (!flock($lock, $operation)) {
                throw new RuntimeException('Configuration storage lock could not be acquired.');
            }
            return $callback();
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
