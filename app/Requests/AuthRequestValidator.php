<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/../Security/UsernamePolicy.php';

final class AuthRequestValidator
{
    public const MAXIMUM_PASSWORD_LENGTH = 1024;

    public function validate(array $request): array
    {
        $action = $request['action'] ?? null;
        if ($action === 'auth.session' || $action === 'auth.logout') {
            $this->rejectUnknown($request, ['action']);
            return ['action' => $action];
        }
        if ($action !== 'auth.login') {
            throw new ApiRequestException('Invalid authentication request.', 'INVALID_AUTH_REQUEST');
        }

        $this->rejectUnknown($request, ['action', 'username', 'password']);
        $details = [];
        try {
            $username = UsernamePolicy::normalize($request['username'] ?? null);
        } catch (InvalidArgumentException $exception) {
            $username = '';
            $details[] = ['path' => 'username', 'message' => $exception->getMessage()];
        }

        $password = $request['password'] ?? null;
        if (!is_string($password) || $password === '') {
            $details[] = ['path' => 'password', 'message' => 'Password is required.'];
        } elseif (strlen($password) > self::MAXIMUM_PASSWORD_LENGTH) {
            $details[] = ['path' => 'password', 'message' => 'Password is too long.'];
        }

        if ($details !== []) {
            throw new ApiRequestException('Invalid authentication request.', 'INVALID_AUTH_REQUEST', $details);
        }

        return ['action' => $action, 'username' => $username, 'password' => $password];
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
            throw new ApiRequestException('Invalid authentication request.', 'INVALID_AUTH_REQUEST', $details);
        }
    }
}
