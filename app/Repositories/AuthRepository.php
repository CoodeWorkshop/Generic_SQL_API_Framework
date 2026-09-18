<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

final class AuthRepository
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? ROOT_PATH . '/config/auth.json';
    }

    public function load(): array
    {
        $configuration = JsonFileStore::load($this->path);
        $this->validate($configuration);
        return $configuration;
    }

    public function save(array $configuration): void
    {
        $this->validate($configuration);
        JsonFileStore::save($this->path, $configuration);
    }

    public function findEnabledUser(string $username): ?array
    {
        $user = $this->findUser($username);
        return $user !== null && $user['enabled'] ? $user : null;
    }

    public function findUser(string $username): ?array
    {
        foreach ($this->load()['users'] as $user) {
            if (strcasecmp($user['username'], $username) === 0) {
                return $user;
            }
        }
        return null;
    }

    public function replacePasswordHash(string $username, string $expectedHash, string $newHash): bool
    {
        return $this->update(function (array &$configuration) use ($username, $expectedHash, $newHash): bool {
            foreach ($configuration['users'] as &$user) {
                if (strcasecmp($user['username'], $username) === 0
                    && hash_equals($user['passwordHash'], $expectedHash)) {
                    $user['passwordHash'] = $newHash;
                    return true;
                }
            }
            return false;
        });
    }

    public function update(callable $operation)
    {
        $lockPath = $this->path . '.lock';
        $stream = @fopen($lockPath, 'c');
        if ($stream === false) {
            throw new RuntimeException('Authentication storage lock is unavailable.');
        }
        @chmod($lockPath, 0600);

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new RuntimeException('Authentication storage lock could not be acquired.');
            }
            $configuration = $this->load();
            $result = $operation($configuration);
            $this->save($configuration);
            return $result;
        } finally {
            @flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function validate(array $configuration): void
    {
        if (array_diff(array_keys($configuration), ['version', 'users']) !== []
            || ($configuration['version'] ?? null) !== 1
            || !is_array($configuration['users'] ?? null)
            || !array_is_list($configuration['users'])) {
            throw new RuntimeException('Invalid authentication configuration.');
        }

        $usernames = [];
        foreach ($configuration['users'] as $user) {
            if (!is_array($user) || array_is_list($user)
                || array_diff(array_keys($user), ['username', 'passwordHash', 'enabled', 'isAdmin']) !== []
                || !is_string($user['username'] ?? null)
                || trim($user['username']) === ''
                || !is_string($user['passwordHash'] ?? null)
                || ($user['passwordHash'] ?? '') === ''
                || (password_get_info($user['passwordHash'])['algoName'] ?? 'unknown') === 'unknown'
                || !is_bool($user['enabled'] ?? null)
                || !is_bool($user['isAdmin'] ?? null)) {
                throw new RuntimeException('Invalid authentication user configuration.');
            }

            $canonicalUsername = strtolower($user['username']);
            if (isset($usernames[$canonicalUsername])) {
                throw new RuntimeException('Authentication usernames must be unique.');
            }
            $usernames[$canonicalUsername] = true;
        }
    }
}
