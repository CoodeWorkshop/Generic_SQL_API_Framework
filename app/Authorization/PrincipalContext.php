<?php

require_once __DIR__ . '/Principal.php';

final class PrincipalContext
{
    private static ?Principal $principal = null;

    public static function set(Principal $principal): void { self::$principal = $principal; }
    public static function current(): ?Principal { return self::$principal; }
    public static function clear(): void { self::$principal = null; }
}
