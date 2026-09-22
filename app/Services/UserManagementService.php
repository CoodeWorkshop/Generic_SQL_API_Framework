<?php

require_once __DIR__ . '/../Authorization/RoleModel.php';
require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Security/PasswordHasher.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../../core/Logger.php';

final class UserManagementService
{
    public function __construct(
        private ?AuthRepository $authRepository = null,
        private ?PasswordHasher $passwordHasher = null,
        private ?Logger $logger = null
    ) {
        $this->authRepository ??= new AuthRepository();
        $this->passwordHasher ??= new PasswordHasher();
        $this->logger ??= new Logger();
    }

    public function listUsers(): array
    {
        return $this->sorted(array_map(fn (array $user): array => $this->safeUser($user), $this->loadUsers()));
    }

    public function listFrontendUsers(): array
    {
        $users = array_filter($this->loadUsers(), fn (array $user): bool => $user['frontendAccess'] === true);
        return $this->sorted(array_map(fn (array $user): array => $this->safeFrontendUser($user), $users));
    }

    public function createUser(string $username, string $password, ?string $backendRole, bool $frontendAccess, ?string $frontendRole, bool $enabled = true): array
    {
        $this->validateAuthorization($backendRole, $frontendAccess, $frontendRole, $enabled);
        return $this->create($username, $password, $backendRole, $frontendAccess, $frontendRole, $enabled, false);
    }

    public function createFrontendUser(string $username, string $password, ?string $frontendRole, bool $enabled = true): array
    {
        if (!in_array($frontendRole, [null, ...RoleModel::frontendRoles()], true)) {
            throw new ApiRequestException('Invalid frontend user request.', 'INVALID_FRONTEND_USER_REQUEST');
        }
        return $this->create($username, $password, null, true, $frontendRole, $enabled, true);
    }

    public function updateUsername(string $username, string $newUsername, bool $frontendOnly = false): array
    {
        return $this->mutate($username, function (array &$configuration, int $index) use ($newUsername, $frontendOnly): void {
            if ($frontendOnly) $this->assertFrontendIdentityMutable($configuration['users'][$index]);
            $existing = $this->findIndex($configuration['users'], $newUsername);
            if ($existing !== null && $existing !== $index) throw new ApiRequestException('User already exists.', 'USER_ALREADY_EXISTS', [], 409);
            if ($configuration['users'][$index]['username'] !== $newUsername) {
                $configuration['users'][$index]['username'] = $newUsername;
                $configuration['users'][$index]['authVersion']++;
            }
        }, $frontendOnly);
    }

    public function setEnabled(string $username, bool $enabled, bool $frontendOnly = false): array
    {
        return $this->mutate($username, function (array &$configuration, int $index) use ($enabled, $frontendOnly): void {
            $user = $configuration['users'][$index];
            if ($frontendOnly) $this->assertFrontendIdentityMutable($user);
            if ($enabled && $user['backendRole'] === null && $user['frontendAccess'] !== true) throw new ApiRequestException('User has no assigned access.', 'USER_ACCESS_REQUIRED', [], 409);
            if (!$enabled && $user['enabled'] && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR
                && $this->enabledSystemAdministratorCount($configuration['users']) <= 1) $this->lastEnabledAdministrator();
            if ($user['enabled'] !== $enabled) {
                $configuration['users'][$index]['enabled'] = $enabled;
                $configuration['users'][$index]['authVersion']++;
            }
        }, $frontendOnly);
    }

    public function deleteUser(string $username, string $currentUsername, bool $frontendOnly = false): array
    {
        try {
            $result = $this->authRepository->update(function (array &$configuration) use ($username, $currentUsername, $frontendOnly): array {
                $index = $this->requireUserIndex($configuration['users'], $username);
                $user = $configuration['users'][$index];
                if ($frontendOnly) $this->assertFrontendIdentityMutable($user);
                if (strcasecmp($user['username'], $currentUsername) === 0) throw new ApiRequestException('The current user cannot be deleted.', 'CANNOT_DELETE_CURRENT_USER', [], 409);
                if ($user['enabled'] && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR
                    && $this->enabledSystemAdministratorCount($configuration['users']) <= 1) $this->lastEnabledAdministrator();
                array_splice($configuration['users'], $index, 1);
                return $frontendOnly ? $this->safeFrontendUser($user) : $this->safeUser($user);
            });
            $this->audit('user_deleted', $username);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    public function changePassword(string $username, string $newPassword, bool $frontendOnly = false): array
    {
        $hash = $this->passwordHasher->hash($newPassword);
        return $this->mutate($username, function (array &$configuration, int $index) use ($hash, $frontendOnly): void {
            if ($frontendOnly) $this->assertFrontendIdentityMutable($configuration['users'][$index]);
            $configuration['users'][$index]['passwordHash'] = $hash;
            $configuration['users'][$index]['authVersion']++;
        }, $frontendOnly);
    }

    public function assignAuthorization(string $username, ?string $backendRole, bool $frontendAccess, ?string $frontendRole): array
    {
        $this->validateAuthorization($backendRole, $frontendAccess, $frontendRole, false);
        return $this->mutate($username, function (array &$configuration, int $index) use ($backendRole, $frontendAccess, $frontendRole): void {
            $user = $configuration['users'][$index];
            if ($user['enabled'] && $backendRole === null && !$frontendAccess) {
                throw new ApiRequestException('An enabled user requires backend or frontend access.', 'USER_ACCESS_REQUIRED', [], 409);
            }
            if ($user['enabled'] && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR
                && $backendRole !== RoleModel::SYSTEM_ADMINISTRATOR
                && $this->enabledSystemAdministratorCount($configuration['users']) <= 1) $this->lastEnabledAdministrator();
            $configuration['users'][$index]['backendRole'] = $backendRole;
            $configuration['users'][$index]['frontendAccess'] = $frontendAccess;
            $configuration['users'][$index]['frontendRole'] = $frontendAccess ? $frontendRole : null;
            $configuration['users'][$index]['authVersion']++;
        });
    }

    public function assignFrontendAuthorization(string $username, ?string $frontendRole): array
    {
        if (!in_array($frontendRole, [null, RoleModel::APPLICATION_ADMINISTRATOR], true)) throw new ApiRequestException('Invalid frontend user request.', 'INVALID_FRONTEND_USER_REQUEST');
        return $this->mutate($username, function (array &$configuration, int $index) use ($frontendRole): void {
            $configuration['users'][$index]['frontendAccess'] = true;
            $configuration['users'][$index]['frontendRole'] = $frontendRole;
            $configuration['users'][$index]['authVersion']++;
        }, true);
    }

    private function create(string $username, string $password, ?string $backendRole, bool $frontendAccess, ?string $frontendRole, bool $enabled, bool $frontendOnly): array
    {
        try {
            $hash = $this->passwordHasher->hash($password);
            $result = $this->authRepository->update(function (array &$configuration) use ($username, $hash, $backendRole, $frontendAccess, $frontendRole, $enabled, $frontendOnly): array {
                if ($this->findIndex($configuration['users'], $username) !== null) throw new ApiRequestException('User already exists.', 'USER_ALREADY_EXISTS', [], 409);
                $user = [
                    'id' => bin2hex(random_bytes(16)), 'username' => $username, 'passwordHash' => $hash,
                    'enabled' => $enabled, 'backendRole' => $backendRole, 'frontendAccess' => $frontendAccess,
                    'frontendRole' => $frontendRole, 'createdAt' => gmdate(DATE_ATOM), 'authVersion' => 1,
                ];
                $configuration['users'][] = $user;
                return $frontendOnly ? $this->safeFrontendUser($user) : $this->safeUser($user);
            });
            $this->audit('user_created', $username);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    private function mutate(string $username, callable $operation, bool $frontendOnly = false): array
    {
        try {
            $result = $this->authRepository->update(function (array &$configuration) use ($username, $operation, $frontendOnly): array {
                $index = $this->requireUserIndex($configuration['users'], $username);
                $operation($configuration, $index);
                return $frontendOnly ? $this->safeFrontendUser($configuration['users'][$index]) : $this->safeUser($configuration['users'][$index]);
            });
            $this->audit('user_updated', $username);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    private function loadUsers(): array
    {
        try { return $this->authRepository->load()['users']; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    private function validateAuthorization(?string $backendRole, bool $frontendAccess, ?string $frontendRole, bool $enabled): void
    {
        if (!in_array($backendRole, [null, ...RoleModel::backendRoles()], true)
            || !in_array($frontendRole, [null, ...RoleModel::frontendRoles()], true)
            || (!$frontendAccess && $frontendRole !== null)
            || ($enabled && $backendRole === null && !$frontendAccess)) {
            throw new ApiRequestException('Invalid user authorization.', 'INVALID_USER_REQUEST');
        }
    }

    private function assertFrontendIdentityMutable(array $user): void
    {
        if ($user['backendRole'] !== null) throw new ApiRequestException('This identity is protected by backend access.', 'BACKEND_IDENTITY_PROTECTED', [], 403);
    }

    private function requireUserIndex(array $users, string $username): int
    {
        $index = $this->findIndex($users, $username);
        if ($index === null) throw new ApiRequestException('User was not found.', 'USER_NOT_FOUND', [], 404);
        return $index;
    }

    private function findIndex(array $users, string $username): ?int
    {
        foreach ($users as $index => $user) if (strcasecmp($user['username'], $username) === 0) return $index;
        return null;
    }

    private function enabledSystemAdministratorCount(array $users): int
    {
        return count(array_filter($users, fn (array $user): bool => $user['enabled'] && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR));
    }

    private function safeUser(array $user): array
    {
        return ['username' => $user['username'], 'enabled' => $user['enabled'], 'backendRole' => $user['backendRole'], 'frontendAccess' => $user['frontendAccess'], 'frontendRole' => $user['frontendRole'], 'createdAt' => $user['createdAt']];
    }

    private function safeFrontendUser(array $user): array
    {
        return ['username' => $user['username'], 'enabled' => $user['enabled'], 'frontendAccess' => $user['frontendAccess'], 'frontendRole' => $user['frontendRole'], 'backendProtected' => $user['backendRole'] !== null];
    }

    private function sorted(array $users): array
    {
        usort($users, fn (array $left, array $right): int => strcasecmp($left['username'], $right['username']));
        return $users;
    }

    private function lastEnabledAdministrator(): never
    {
        throw new ApiRequestException('At least one enabled System Administrator is required.', 'LAST_ENABLED_ADMIN', [], 409);
    }

    private function audit(string $event, string $targetUsername): void
    {
        $this->logger->security($event, ['targetUsername' => $targetUsername, 'result' => 'completed']);
    }

    private function fail(Throwable $exception): never
    {
        $this->logger->write((string)json_encode(['timestamp' => date(DATE_ATOM), 'requestId' => defined('API_REQUEST_ID') ? API_REQUEST_ID : null, 'event' => 'user_management_error', 'errorType' => get_class($exception)], JSON_UNESCAPED_SLASHES));
        throw new ApiRequestException('Unable to complete user operation.', 'USER_OPERATION_FAILED', [], 500);
    }
}
