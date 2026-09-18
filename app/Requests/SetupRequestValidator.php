<?php

require_once __DIR__ . '/ApiRequestException.php';

final class SetupRequestValidator
{
    public const MINIMUM_PASSWORD_LENGTH = 12;
    public const MAXIMUM_PASSWORD_LENGTH = 1024;
    public const MAXIMUM_USERNAME_LENGTH = 64;

    public function validate(array $request): array
    {
        $action = $request['action'] ?? null;
        if ($action === 'setup.status') {
            $this->rejectUnknown($request, ['action']);
            return ['action' => $action];
        }
        if ($action !== 'setup.createAdmin') {
            throw new ApiRequestException('Invalid setup request.', 'INVALID_SETUP_REQUEST');
        }

        $this->rejectUnknown($request, ['action', 'username', 'password', 'passwordConfirmation']);
        $details = [];
        $username = $request['username'] ?? null;
        $password = $request['password'] ?? null;
        $confirmation = $request['passwordConfirmation'] ?? null;

        if (!is_string($username)) {
            $details[] = ['path' => 'username', 'message' => 'Username is required.'];
        } else {
            $username = trim($username);
            if ($username === '') {
                $details[] = ['path' => 'username', 'message' => 'Username is required.'];
            } elseif (strlen($username) > self::MAXIMUM_USERNAME_LENGTH
                || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?$/', $username) !== 1) {
                $details[] = [
                    'path' => 'username',
                    'message' => 'Username must use letters, numbers, periods, underscores, or hyphens.',
                ];
            }
        }

        if (!is_string($password) || $password === '') {
            $details[] = ['path' => 'password', 'message' => 'Password is required.'];
        } elseif (strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            $details[] = [
                'path' => 'password',
                'message' => 'Password must be at least ' . self::MINIMUM_PASSWORD_LENGTH . ' characters.',
            ];
        } elseif (strlen($password) > self::MAXIMUM_PASSWORD_LENGTH) {
            $details[] = ['path' => 'password', 'message' => 'Password is too long.'];
        }

        if (!is_string($confirmation)) {
            $details[] = ['path' => 'passwordConfirmation', 'message' => 'Password confirmation is required.'];
        } elseif (is_string($password) && !hash_equals($password, $confirmation)) {
            $details[] = ['path' => 'passwordConfirmation', 'message' => 'Password confirmation does not match.'];
        }

        if ($details !== []) {
            throw new ApiRequestException('Invalid setup request.', 'INVALID_SETUP_REQUEST', $details);
        }

        return [
            'action' => $action,
            'username' => $username,
            'password' => $password,
        ];
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
            throw new ApiRequestException('Invalid setup request.', 'INVALID_SETUP_REQUEST', $details);
        }
    }
}
