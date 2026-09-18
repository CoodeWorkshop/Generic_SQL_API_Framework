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
        foreach ($this->load()['users'] as $user) {
            if ($user['enabled'] && strcasecmp($user['username'], $username) === 0) {
                return $user;
            }
        }
        return null;
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
