<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';

final class AuthRepository
{
    private string $path;
    private bool $runtimePath;

    public function __construct(?string $path = null)
    {
        $this->runtimePath = $path === null;
        $this->path = $path ?? RuntimeConfiguration::path(RuntimeConfiguration::AUTH_FILE);
    }

    public function load(): array
    {
        $this->bootstrapRuntimeConfiguration();
        $configuration = JsonFileStore::load($this->path);
        if (in_array($configuration['version'] ?? null, [1, 2], true)) {
            return $this->withLock(function (): array {
                $configuration = JsonFileStore::load($this->path);
                if (in_array($configuration['version'] ?? null, [1, 2], true)) {
                    $configuration = $this->migrateLegacyConfiguration($configuration);
                    JsonFileStore::save($this->path, $configuration);
                }
                $this->validate($configuration);
                return $configuration;
            });
        }
        $this->validate($configuration);
        return $configuration;
    }

    public function save(array $configuration): void
    {
        $this->bootstrapRuntimeConfiguration();
        if (in_array($configuration['version'] ?? null, [1, 2], true)) {
            $configuration = $this->migrateLegacyConfiguration($configuration);
        }
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

    public function findUserById(string $id): ?array
    {
        foreach ($this->load()['users'] as $user) {
            if (hash_equals($user['id'], $id)) return $user;
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
        $this->bootstrapRuntimeConfiguration();
        return $this->withLock(function () use ($operation) {
            $configuration = JsonFileStore::load($this->path);
            if (in_array($configuration['version'] ?? null, [1, 2], true)) {
                $configuration = $this->migrateLegacyConfiguration($configuration);
            }
            $this->validate($configuration);
            $result = $operation($configuration);
            $this->save($configuration);
            return $result;
        });
    }

    private function validate(array $configuration): void
    {
        if (array_diff(array_keys($configuration), ['version', 'users']) !== []
            || ($configuration['version'] ?? null) !== 3
            || !is_array($configuration['users'] ?? null)
            || !array_is_list($configuration['users'])) {
            throw new RuntimeException('Invalid authentication configuration.');
        }

        $usernames = [];
        $userIds = [];
        foreach ($configuration['users'] as $user) {
            if (!is_array($user) || array_is_list($user)
                || array_diff(array_keys($user), [
                    'id', 'username', 'passwordHash', 'enabled', 'isAdmin', 'roles', 'createdAt', 'authVersion'
                ]) !== []
                || !is_string($user['id'] ?? null)
                || preg_match('/^[a-f0-9]{32}$/', $user['id']) !== 1
                || !is_string($user['username'] ?? null)
                || trim($user['username']) === ''
                || !is_string($user['passwordHash'] ?? null)
                || ($user['passwordHash'] ?? '') === ''
                || (password_get_info($user['passwordHash'])['algoName'] ?? 'unknown') === 'unknown'
                || !is_bool($user['enabled'] ?? null)
                || !is_bool($user['isAdmin'] ?? null)
                || !is_array($user['roles'] ?? null)
                || !array_is_list($user['roles'])
                || $user['roles'] === []
                || count(array_filter($user['roles'], fn ($role): bool => !is_string($role) || preg_match('/^[a-z][a-z0-9-]*$/', $role) !== 1)) > 0
                || count(array_unique($user['roles'])) !== count($user['roles'])
                || $user['isAdmin'] !== in_array('admin', $user['roles'], true)
                || !is_string($user['createdAt'] ?? null)
                || strtotime($user['createdAt']) === false
                || !is_int($user['authVersion'] ?? null)
                || $user['authVersion'] < 1) {
                throw new RuntimeException('Invalid authentication user configuration.');
            }

            $canonicalUsername = strtolower($user['username']);
            if (isset($usernames[$canonicalUsername]) || isset($userIds[$user['id']])) {
                throw new RuntimeException('Authentication usernames must be unique.');
            }
            $usernames[$canonicalUsername] = true;
            $userIds[$user['id']] = true;
        }
    }

    private function migrateLegacyConfiguration(array $configuration): array
    {
        if (!in_array($configuration['version'] ?? null, [1, 2], true)
            || !is_array($configuration['users'] ?? null)
            || !array_is_list($configuration['users'])) {
            throw new RuntimeException('Invalid authentication configuration.');
        }
        $createdAt = gmdate(DATE_ATOM, (int)(@filemtime($this->path) ?: time()));
        $sourceVersion = $configuration['version'];
        $configuration['version'] = 3;
        foreach ($configuration['users'] as &$user) {
            if (!is_array($user) || array_is_list($user)) {
                throw new RuntimeException('Invalid authentication user configuration.');
            }
            $isAdmin = ($user['isAdmin'] ?? null) === true;
            $user = [
                'id' => $sourceVersion === 2 ? ($user['id'] ?? null) : bin2hex(random_bytes(16)),
                'username' => $user['username'] ?? null,
                'passwordHash' => $user['passwordHash'] ?? null,
                'enabled' => $user['enabled'] ?? null,
                'isAdmin' => $isAdmin,
                'roles' => [$isAdmin ? 'admin' : 'viewer'],
                'createdAt' => $sourceVersion === 2 ? ($user['createdAt'] ?? null) : $createdAt,
                'authVersion' => $sourceVersion === 2 ? ($user['authVersion'] ?? null) : 1,
            ];
        }
        unset($user);
        $this->validate($configuration);
        return $configuration;
    }

    private function withLock(callable $operation)
    {
        $lockPath = $this->path . '.lock';
        $stream = @fopen($lockPath, 'c');
        if ($stream === false) throw new RuntimeException('Authentication storage lock is unavailable.');
        @chmod($lockPath, 0600);
        try {
            if (!flock($stream, LOCK_EX)) {
                throw new RuntimeException('Authentication storage lock could not be acquired.');
            }
            return $operation();
        } finally {
            @flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function bootstrapRuntimeConfiguration(): void
    {
        if ($this->runtimePath) RuntimeConfiguration::ensure();
    }
}
