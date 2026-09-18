<?php

final class UsernamePolicy
{
    public const MAXIMUM_LENGTH = 64;
    public const FORMAT_MESSAGE = 'Username must use letters, numbers, periods, underscores, or hyphens.';

    public static function normalize(mixed $username): string
    {
        if (!is_string($username) || trim($username) === '') {
            throw new InvalidArgumentException('Username is required.');
        }

        $username = trim($username);
        if (strlen($username) > self::MAXIMUM_LENGTH
            || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?$/', $username) !== 1) {
            throw new InvalidArgumentException(self::FORMAT_MESSAGE);
        }

        return $username;
    }
}
