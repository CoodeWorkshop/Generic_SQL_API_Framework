<?php

require_once __DIR__ . '/../Authorization/Principal.php';
require_once __DIR__ . '/../Repositories/AuthorizationRepository.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class AuthorizationService
{
    public function __construct(private ?AuthorizationRepository $repository = null) { $this->repository ??= new AuthorizationRepository(); }

    public function roles(): array { return $this->repository->load()['roles']; }
    public function publicRoles(): array { return $this->repository->load()['publicRoles']; }
    public function legacyApiKeyRoles(): array { return $this->repository->load()['legacyApiKeyRoles']; }
    public function roleExists(string $role): bool { return isset($this->roles()[$role]); }
    public function permissionsForRoles(array $roles): array
    {
        $definitions=$this->roles();$permissions=[];
        foreach($roles as $role)foreach($definitions[$role]['permissions']??[] as $permission)$permissions[$permission]=true;
        return array_keys($permissions);
    }

    public function authorize(Principal $principal, string $permission, ?string $resource = null, ?string $scope = null): void
    {
        if (!$principal->enabled) $this->deny(false);
        $configuration = $this->repository->load();
        $allowed = false;
        $resourceAllowed = $resource === null;
        foreach ($principal->roles as $roleId) {
            $role = $configuration['roles'][$roleId] ?? null;
            if ($role === null || !in_array($permission, $role['permissions'], true)) continue;
            $allowed = true;
            if ($resource === null) { $resourceAllowed = true; continue; }
            $resources = $scope === 'write' ? $role['writeResources'] : $role['sqlResources'];
            if (in_array('*', $resources, true) || in_array($resource, $resources, true)) $resourceAllowed = true;
        }
        if (!$allowed || !$resourceAllowed) $this->deny($resource !== null);
    }

    private function deny(bool $resource): never
    {
        throw new ApiRequestException(
            $resource ? 'Resource access is denied.' : 'Authorization denied.',
            $resource ? 'RESOURCE_ACCESS_DENIED' : 'AUTHORIZATION_DENIED', [], 403
        );
    }
}
