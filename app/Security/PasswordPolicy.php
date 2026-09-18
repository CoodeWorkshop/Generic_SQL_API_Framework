<?php

final class PasswordPolicy
{
    public const MINIMUM_LENGTH = 12;
    public const MAXIMUM_LENGTH = 1024;

    public static function validate(mixed $password): string
    {
        if (!is_string($password) || $password === '') {
            throw new InvalidArgumentException('Password is required.');
        }
        if (strlen($password) < self::MINIMUM_LENGTH) {
            throw new InvalidArgumentException(
                'Password must be at least ' . self::MINIMUM_LENGTH . ' characters.'
            );
        }
        if (strlen($password) > self::MAXIMUM_LENGTH) {
            throw new InvalidArgumentException('Password is too long.');
        }
        return $password;
    }
}
