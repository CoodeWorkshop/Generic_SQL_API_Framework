<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';

final class ApiKeyRepository
{
    private string $path;
    public function __construct(?string $path = null) { $this->path = $path ?? RuntimeConfiguration::path(RuntimeConfiguration::API_KEYS_FILE); }
    public function load(): array { RuntimeConfiguration::ensure(); $value = JsonFileStore::load($this->path); if(($value['version']??null)===1){$value=$this->migrate($value);JsonFileStore::save($this->path,$value);}$this->validate($value); return $value; }
    public function update(callable $operation) {
        RuntimeConfiguration::ensure(); $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('API key storage is unavailable.');
        try { $value = JsonFileStore::load($this->path); if(($value['version']??null)===1)$value=$this->migrate($value);$this->validate($value); $result = $operation($value); $this->validate($value); JsonFileStore::save($this->path, $value); return $result; }
        finally { @flock($lock, LOCK_UN); if (is_resource($lock)) fclose($lock); }
    }
    public function findById(string $id): ?array { foreach ($this->load()['keys'] as $key) if (hash_equals($key['id'], $id)) return $key; return null; }
    private function validate(array $value): void {
        if (($value['version'] ?? null) !== 2 || array_diff(array_keys($value), ['version', 'keys']) !== [] || !is_array($value['keys'] ?? null) || !array_is_list($value['keys'])) throw new RuntimeException('Invalid API key configuration.');
        $ids = [];
        foreach ($value['keys'] as $key) {
            if (!is_array($key) || array_diff(array_keys($key), ['id','name','ownerUserId','roles','secretHash','fingerprint','enabled','revokedAt','createdAt','lastUsedAt']) !== []
                || preg_match('/^[a-f0-9]{16}$/', $key['id'] ?? '') !== 1 || isset($ids[$key['id']]) || !is_string($key['name'] ?? null) || trim($key['name']) === ''
                || preg_match('/^[a-f0-9]{32}$/', $key['ownerUserId'] ?? '') !== 1 || !is_array($key['roles'] ?? null) || !array_is_list($key['roles']) || count($key['roles']) !== 1 || !in_array($key['roles'][0],RoleModel::backendRoles(),true)
                || !is_string($key['secretHash'] ?? null) || (password_get_info($key['secretHash'])['algoName'] ?? 'unknown') === 'unknown'
                || preg_match('/^[a-f0-9]{12}$/', $key['fingerprint'] ?? '') !== 1 || !is_bool($key['enabled'] ?? null)
                || !is_string($key['createdAt'] ?? null) || strtotime($key['createdAt']) === false
                || ($key['revokedAt'] !== null && (!is_string($key['revokedAt']) || strtotime($key['revokedAt']) === false))
                || ($key['lastUsedAt'] !== null && (!is_string($key['lastUsedAt']) || strtotime($key['lastUsedAt']) === false))) throw new RuntimeException('Invalid API key record.');
            $ids[$key['id']] = true;
        }
    }
    private function migrate(array$value):array{if(($value['version']??null)!==1||!is_array($value['keys']??null))throw new RuntimeException('Invalid API key configuration.');$value['version']=2;foreach($value['keys']as&$key){$role=RoleModel::migrateLegacyBackendRoles(is_array($key['roles']??null)?$key['roles']:[]);$key['roles']=[$role??RoleModel::READ_ONLY];}unset($key);return$value;}
}
