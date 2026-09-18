<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/../Security/UsernamePolicy.php';
require_once __DIR__ . '/../Security/PasswordPolicy.php';

final class UserManagementRequestValidator
{
    private const ACTIONS = [
        'auth.users.list',
        'auth.users.create',
        'auth.users.enable',
        'auth.users.disable',
        'auth.users.delete',
        'auth.users.changePassword',
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
            ? ['action', 'username', 'password', 'isAdmin']
            : ($action === 'auth.users.changePassword'
                ? ['action', 'username', 'newPassword']
                : ['action', 'username']);
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
            $isAdmin = $request['isAdmin'] ?? false;
            if (!is_bool($isAdmin)) {
                $details[] = ['path' => 'isAdmin', 'message' => 'Administrator flag must be a boolean.'];
            } else {
                $validated['isAdmin'] = $isAdmin;
            }
        } elseif ($action === 'auth.users.changePassword') {
            try {
                $validated['newPassword'] = PasswordPolicy::validate($request['newPassword'] ?? null);
            } catch (InvalidArgumentException $exception) {
                $details[] = ['path' => 'newPassword', 'message' => $exception->getMessage()];
            }
        }

        if ($details !== []) {
            throw new ApiRequestException('Invalid user request.', 'INVALID_USER_REQUEST', $details);
        }
        return $validated;
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
