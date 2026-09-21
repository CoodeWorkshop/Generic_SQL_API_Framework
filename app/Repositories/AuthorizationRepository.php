<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

final class AuthorizationRepository
{
    private string $path;
    public function __construct(?string $path = null) { $this->path = $path ?? RuntimeConfiguration::path(RuntimeConfiguration::AUTHORIZATION_FILE); }

    public function load(): array
    {
        RuntimeConfiguration::ensure();
        $value = JsonFileStore::load($this->path);
        $this->validate($value);
        return $value;
    }

    public function save(array $value): void
    {
        $this->validate($value);
        JsonFileStore::save($this->path, $value);
    }

    public function roleIds(): array { return array_keys($this->load()['roles']); }

    private function validate(array $value): void
    {
        if (($value['version'] ?? null) !== 1 || array_diff(array_keys($value), ['version', 'publicRoles', 'legacyApiKeyRoles', 'roles']) !== []
            || !is_array($value['roles'] ?? null) || array_is_list($value['roles']) || $value['roles'] === []) {
            throw new RuntimeException('Invalid authorization configuration.');
        }
        foreach (['publicRoles', 'legacyApiKeyRoles'] as $field) {
            if (!is_array($value[$field] ?? null) || !array_is_list($value[$field])) throw new RuntimeException('Invalid authorization role assignment.');
        }
        $allowedPermissions = ['admin.manage', 'data.read', 'data.write', 'metadata.read', 'sql.execute', 'routine.execute'];
        foreach ($value['roles'] as $id => $role) {
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1 || !is_array($role)
                || array_diff(array_keys($role), ['name', 'permissions', 'sqlResources', 'writeResources']) !== []
                || !is_string($role['name'] ?? null) || trim($role['name']) === '') throw new RuntimeException('Invalid authorization role.');
            foreach (['permissions', 'sqlResources', 'writeResources'] as $field) {
                if (!is_array($role[$field] ?? null) || !array_is_list($role[$field]) || count(array_unique($role[$field])) !== count($role[$field])) throw new RuntimeException('Invalid authorization role.');
            }
            if (array_diff($role['permissions'], $allowedPermissions) !== []) throw new RuntimeException('Invalid authorization permission.');
            foreach (array_merge($role['sqlResources'], $role['writeResources']) as $resource) {
                if (!is_string($resource) || ($resource !== '*' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_\/-]*$/', $resource) !== 1)) throw new RuntimeException('Invalid authorization resource scope.');
            }
        }
        foreach (array_merge($value['publicRoles'], $value['legacyApiKeyRoles']) as $role) {
            if (!is_string($role) || !isset($value['roles'][$role])) throw new RuntimeException('Unknown authorization role.');
        }
        if (!isset($value['roles']['admin']) || !in_array('admin.manage', $value['roles']['admin']['permissions'], true)) throw new RuntimeException('Administrator authorization role is required.');
    }
}
