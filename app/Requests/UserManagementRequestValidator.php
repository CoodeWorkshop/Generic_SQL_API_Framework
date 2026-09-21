<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/../Security/UsernamePolicy.php';
require_once __DIR__ . '/../Security/PasswordPolicy.php';
require_once __DIR__ . '/../Services/AuthorizationService.php';

final class UserManagementRequestValidator
{
    private AuthorizationService $authorization;
    public function __construct(?AuthorizationService $authorization=null){$this->authorization=$authorization??new AuthorizationService();}
    private const ACTIONS = [
        'auth.users.list',
        'auth.users.create',
        'auth.users.update',
        'auth.users.enable',
        'auth.users.disable',
        'auth.users.delete',
        'auth.users.changePassword',
        'auth.users.assignRoles',
    ];

    public function validate(array $request): array
    {
        $action = $request['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            throw new ApiRequestException('Invalid user request.', 'INVALID_USER_REQUEST');
        }
        if ($action === 'auth.users.list') {
            $this->rejectUnknown($request, ['action']);
            return ['action' => $action];
        }

        $allowed = $action === 'auth.users.create'
            ? ['action', 'username', 'password', 'passwordConfirmation', 'enabled', 'isAdmin']
            : ($action === 'auth.users.update'
                ? ['action', 'username', 'newUsername']
            : ($action === 'auth.users.assignRoles'
                ? ['action', 'username', 'roles']
            : ($action === 'auth.users.changePassword'
                ? ['action', 'username', 'newPassword', 'passwordConfirmation']
                : ['action', 'username'])));
        $this->rejectUnknown($request, $allowed);

        $details = [];
        try {
            $username = UsernamePolicy::normalize($request['username'] ?? null);
        } catch (InvalidArgumentException $exception) {
            $username = '';
            $details[] = ['path' => 'username', 'message' => $exception->getMessage()];
        }

        $validated = ['action' => $action, 'username' => $username];
        if ($action === 'auth.users.create') {
            try {
                $validated['password'] = PasswordPolicy::validate($request['password'] ?? null);
            } catch (InvalidArgumentException $exception) {
                $details[] = ['path' => 'password', 'message' => $exception->getMessage()];
            }
            $this->validateConfirmation(
                $request['passwordConfirmation'] ?? null,
                $validated['password'] ?? null,
                'passwordConfirmation',
                $details
            );
            $isAdmin = $request['isAdmin'] ?? false;
            if (!is_bool($isAdmin)) {
                $details[] = ['path' => 'isAdmin', 'message' => 'Administrator flag must be a boolean.'];
            } else {
                $validated['isAdmin'] = $isAdmin;
            }
            $enabled = $request['enabled'] ?? true;
            if (!is_bool($enabled)) {
                $details[] = ['path' => 'enabled', 'message' => 'Enabled status must be a boolean.'];
            } else {
                $validated['enabled'] = $enabled;
            }
        } elseif ($action === 'auth.users.update') {
            try {
                $validated['newUsername'] = UsernamePolicy::normalize($request['newUsername'] ?? null);
            } catch (InvalidArgumentException $exception) {
                $details[] = ['path' => 'newUsername', 'message' => $exception->getMessage()];
            }
        } elseif ($action === 'auth.users.assignRoles') {
            $roles = $request['roles'] ?? null;
            if (!is_array($roles) || !array_is_list($roles) || $roles === [] || count(array_unique($roles)) !== count($roles)
                || count(array_filter($roles, fn($role):bool=>!is_string($role)||!$this->authorization->roleExists($role)))>0) {
                $details[] = ['path' => 'roles', 'message' => 'At least one valid unique role is required.'];
            } else $validated['roles'] = $roles;
        } elseif ($action === 'auth.users.changePassword') {
            try {
                $validated['newPassword'] = PasswordPolicy::validate($request['newPassword'] ?? null);
            } catch (InvalidArgumentException $exception) {
                $details[] = ['path' => 'newPassword', 'message' => $exception->getMessage()];
            }
            $this->validateConfirmation(
                $request['passwordConfirmation'] ?? null,
                $validated['newPassword'] ?? null,
                'passwordConfirmation',
                $details
            );
        }

        if ($details !== []) {
            throw new ApiRequestException('Invalid user request.', 'INVALID_USER_REQUEST', $details);
        }
        return $validated;
    }

    private function validateConfirmation($confirmation, ?string $password, string $path, array &$details): void
    {
        if (!is_string($confirmation)) {
            $details[] = ['path' => $path, 'message' => 'Password confirmation is required.'];
        } elseif ($password !== null && !hash_equals($password, $confirmation)) {
            $details[] = ['path' => $path, 'message' => 'Password confirmation does not match.'];
        }
    }

    private function rejectUnknown(array $request, array $allowed): void
    {
        $details = [];
        foreach (array_keys($request) as $key) {
            if (!in_array($key, $allowed, true)) {
                $details[] = ['path' => $key, 'message' => 'Unknown property.'];
            }
        }
        if ($details !== []) {
            throw new ApiRequestException('Invalid user request.', 'INVALID_USER_REQUEST', $details);
        }
    }
}
