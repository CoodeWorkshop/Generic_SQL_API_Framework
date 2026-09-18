<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/SecurityConfiguration.php';

final class LoginRateLimiter
{
    private string $directory;
    private bool $enabled;
    private int $maximumAttempts;
    private int $windowSeconds;
    private int $lockoutSeconds;

    public function __construct(?string $directory = null, ?array $options = null)
    {
        $options ??= SecurityConfiguration::loginRateLimitOptions();
        $this->directory = $directory ?? ROOT_PATH . '/storage/security/login-rate-limit';
        $this->enabled = ($options['enabled'] ?? true) === true;
        $this->maximumAttempts = max(2, (int)($options['maximumAttempts'] ?? 5));
        $this->windowSeconds = max(60, (int)($options['windowSeconds'] ?? 900));
        $this->lockoutSeconds = max(30, (int)($options['lockoutSeconds'] ?? 300));
    }

    public function assertAllowed(string $sourceIp, string $username): void
    {
        if (!$this->enabled) return;
        $record = $this->read($this->key($sourceIp, $username));
        if (($record['blockedUntil'] ?? 0) > time()) {
            $this->rateLimited();
        }
    }

    public function recordFailure(string $sourceIp, string $username): bool
    {
        if (!$this->enabled) return false;
        $key = $this->key($sourceIp, $username);
        return $this->withLock($key, function (array $record): array {
            $now = time();
            $attempts = array_values(array_filter(
                is_array($record['attempts'] ?? null) ? $record['attempts'] : [],
                fn ($timestamp): bool => is_int($timestamp) && $timestamp >= $now - $this->windowSeconds
            ));
            $attempts[] = $now;
            $blocked = count($attempts) >= $this->maximumAttempts;
            return [[
                'attempts' => $attempts,
                'blockedUntil' => $blocked ? $now + $this->lockoutSeconds : 0,
            ], $blocked];
        });
    }

    public function reset(string $sourceIp, string $username): void
    {
        if (!$this->enabled) return;
        $path = $this->path($this->key($sourceIp, $username));
        if (is_file($path)) @unlink($path);
    }

    public function throwRateLimited(): never
    {
        $this->rateLimited();
    }

    private function key(string $sourceIp, string $username): string
    {
        return hash('sha256', strtolower(trim($sourceIp)) . "\0" . strtolower(trim($username)));
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private function read(string $key): array
    {
        $path = $this->path($key);
        if (!is_file($path)) return [];
        $decoded = json_decode((string)@file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function withLock(string $key, callable $operation): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Login protection storage is unavailable.');
        }
        $lock = @fopen($this->path($key) . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Login protection lock is unavailable.');
        }
        try {
            [$record, $result] = $operation($this->read($key));
            $temporary = $this->path($key) . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($temporary, json_encode($record, JSON_THROW_ON_ERROR), LOCK_EX) === false
                || !@rename($temporary, $this->path($key))) {
                @unlink($temporary);
                throw new RuntimeException('Login protection state could not be stored.');
            }
            @chmod($this->path($key), 0600);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function rateLimited(): never
    {
        throw new ApiRequestException(
            'Too many login attempts. Please try again later.',
            'LOGIN_RATE_LIMITED',
            [],
            429
        );
    }
}
