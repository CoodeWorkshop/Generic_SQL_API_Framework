<?php

final class RequestId
{
    private static ?string $generated = null;

    public static function get(): string
    {
        if (defined('API_REQUEST_ID') && is_string(API_REQUEST_ID) && API_REQUEST_ID !== '') {
            return API_REQUEST_ID;
        }
        if (self::$generated === null) {
            try { self::$generated = bin2hex(random_bytes(8)); }
            catch (Throwable $exception) { self::$generated = str_replace('.', '', uniqid('request-', true)); }
        }
        return self::$generated;
    }
}
