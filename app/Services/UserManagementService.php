<?php

require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Security/PasswordHasher.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/AuthorizationService.php';

final class UserManagementService
{
    private AuthRepository $authRepository;
    private PasswordHasher $passwordHasher;
    private Logger $logger;
    private AuthorizationService $authorization;

    public function __construct(
        ?AuthRepository $authRepository = null,
        ?PasswordHasher $passwordHasher = null,
        ?Logger $logger = null,
        ?AuthorizationService $authorization = null
    ) {
        $this->authRepository = $authRepository ?? new AuthRepository();
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
        $this->logger = $logger ?? new Logger();
        $this->authorization = $authorization ?? new AuthorizationService();
    }

    public function listUsers(): array
    {
        try {
            $users = array_map(fn (array $user): array => $this->safeUser($user), $this->authRepository->load()['users']);
            usort($users, fn (array $left, array $right): int => strcasecmp($left['username'], $right['username']));
            return $users;
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function createUser(string $username, string $password, bool $isAdmin, bool $enabled = true, ?array $roles = null): array
    {
        try {
            $passwordHash = $this->passwordHasher->hash($password);
            if ($roles !== null) $this->validateRoles($roles);
            $result = $this->authRepository->update(function (array &$configuration) use (
                $username,
                $passwordHash,
                $isAdmin,
                $enabled,
                $roles
            ): array {
                if ($this->findIndex($configuration['users'], $username) !== null) {
                    throw new ApiRequestException('User already exists.', 'USER_ALREADY_EXISTS', [], 409);
                }
                $assignedRoles = $roles ?? [$isAdmin ? 'admin' : 'viewer'];
                $isAdmin = in_array('admin', $assignedRoles, true);
                $user = [
                    'id' => bin2hex(random_bytes(16)),
                    'username' => $username,
                    'passwordHash' => $passwordHash,
                    'enabled' => $enabled,
                    'isAdmin' => $isAdmin,
                    'roles' => $assignedRoles,
                    'createdAt' => gmdate(DATE_ATOM),
                    'authVersion' => 1,
                ];
                $configuration['users'][] = $user;
                return $this->safeUser($user);
            });
            $this->audit('admin_user_created', $username);
            return $result;
        } catch (ApiRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function updateUsername(string $username, string $newUsername): array
    {
        try {
            $result = $this->authRepository->update(function (array &$configuration) use (
                $username,
                $newUsername
            ): array {
                $index = $this->requireUserIndex($configuration['users'], $username);
                $existingIndex = $this->findIndex($configuration['users'], $newUsername);
                if ($existingIndex !== null && $existingIndex !== $index) {
                    throw new ApiRequestException('User already exists.', 'USER_ALREADY_EXISTS', [], 409);
                }
                if ($configuration['users'][$index]['username'] !== $newUsername) {
                    $configuration['users'][$index]['username'] = $newUsername;
                    $configuration['users'][$index]['authVersion']++;
                }
                return $this->safeUser($configuration['users'][$index]);
            });
            $this->audit('admin_username_updated', $newUsername);
            return $result;
        } catch (ApiRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function setEnabled(string $username, bool $enabled): array
    {
        try {
            $result = $this->authRepository->update(function (array &$configuration) use ($username, $enabled): array {
                $index = $this->requireUserIndex($configuration['users'], $username);
                $user = $configuration['users'][$index];
                if (!$enabled && $user['enabled'] && $user['isAdmin']
                    && $this->enabledAdminCount($configuration['users']) <= 1) {
                    $this->lastEnabledAdmin();
                }
                if ($configuration['users'][$index]['enabled'] !== $enabled) {
                    $configuration['users'][$index]['enabled'] = $enabled;
                    $configuration['users'][$index]['authVersion']++;
                }
                return $this->safeUser($configuration['users'][$index]);
            });
            $this->audit($enabled ? 'admin_user_enabled' : 'admin_user_disabled', $username);
            return $result;
        } catch (ApiRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function deleteUser(string $username, string $currentUsername): array
    {
        try {
            $result = $this->authRepository->update(function (array &$configuration) use (
                $username,
                $currentUsername
            ): array {
                $index = $this->requireUserIndex($configuration['users'], $username);
                $user = $configuration['users'][$index];
                if (strcasecmp($user['username'], $currentUsername) === 0) {
                    throw new ApiRequestException(
                        'The current user cannot be deleted.',
                        'CANNOT_DELETE_CURRENT_USER',
                        [],
                        409
                    );
                }
                if ($user['enabled'] && $user['isAdmin']
                    && $this->enabledAdminCount($configuration['users']) <= 1) {
                    $this->lastEnabledAdmin();
                }
                array_splice($configuration['users'], $index, 1);
                return $this->safeUser($user);
            });
            $this->audit('admin_user_deleted', $username);
            return $result;
        } catch (ApiRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function changePassword(string $username, string $newPassword): array
    {
        try {
            $passwordHash = $this->passwordHasher->hash($newPassword);
            $result = $this->authRepository->update(function (array &$configuration) use (
                $username,
                $passwordHash
            ): array {
                $index = $this->requireUserIndex($configuration['users'], $username);
                $configuration['users'][$index]['passwordHash'] = $passwordHash;
                $configuration['users'][$index]['authVersion']++;
                return $this->safeUser($configuration['users'][$index]);
            });
            $this->audit('admin_user_password_changed', $username);
            return $result;
        } catch (ApiRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function assignRoles(string $username, array $roles): array
    {
        try {
            $roles = array_values(array_unique($roles));
            $this->validateRoles($roles);
            $result = $this->authRepository->update(function (array &$configuration) use ($username, $roles): array {
                $index = $this->requireUserIndex($configuration['users'], $username);
                $user = $configuration['users'][$index];
                if ($user['enabled'] && $user['isAdmin'] && !in_array('admin', $roles, true)
                    && $this->enabledAdminCount($configuration['users']) <= 1) $this->lastEnabledAdmin();
                $configuration['users'][$index]['roles'] = $roles;
                $configuration['users'][$index]['isAdmin'] = in_array('admin', $roles, true);
                $configuration['users'][$index]['authVersion']++;
                return $this->safeUser($configuration['users'][$index]);
            });
            $this->audit('admin_user_roles_changed', $username);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    private function requireUserIndex(array $users, string $username): int
    {
        $index = $this->findIndex($users, $username);
        if ($index === null) {
            throw new ApiRequestException('User was not found.', 'USER_NOT_FOUND', [], 404);
        }
        return $index;
    }

    private function findIndex(array $users, string $username): ?int
    {
        foreach ($users as $index => $user) {
            if (strcasecmp($user['username'], $username) === 0) {
                return $index;
            }
        }
        return null;
    }

    private function enabledAdminCount(array $users): int
    {
        return count(array_filter(
            $users,
            fn (array $user): bool => $user['enabled'] === true && $user['isAdmin'] === true
        ));
    }

    private function safeUser(array $user): array
    {
        return [
            'username' => $user['username'],
            'enabled' => $user['enabled'],
            'isAdmin' => $user['isAdmin'],
            'roles' => $user['roles'],
            'createdAt' => $user['createdAt'],
        ];
    }

    private function lastEnabledAdmin(): never
    {
        throw new ApiRequestException(
            'At least one enabled administrator is required.',
            'LAST_ENABLED_ADMIN',
            [],
            409
        );
    }

    private function validateRoles(array $roles): void
    {
        if ($roles === [] || count(array_filter($roles, fn($role):bool=>!is_string($role)||!$this->authorization->roleExists($role)))>0) {
            throw new ApiRequestException('Invalid user request.','INVALID_USER_REQUEST',[['path'=>'roles','message'=>'At least one valid role is required.']]);
        }
    }

    private function audit(string $event, string $targetUsername): void
    {
        $this->logger->security($event, [
            'targetUsername' => $targetUsername,
            'result' => 'completed',
        ]);
    }

    private function fail(Throwable $exception): never
    {
        $this->logger->write((string)json_encode([
            'timestamp' => date(DATE_ATOM),
            'requestId' => defined('API_REQUEST_ID') ? API_REQUEST_ID : null,
            'event' => 'user_management_error',
            'errorType' => get_class($exception),
        ], JSON_UNESCAPED_SLASHES));
        throw new ApiRequestException(
            'Unable to complete user operation.',
            'USER_OPERATION_FAILED',
            [],
            500
        );
    }
}
