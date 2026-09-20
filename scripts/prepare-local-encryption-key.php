<?php

require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../app/Security/DatabaseConfigurationResolver.php';

$root = dirname(__DIR__);
$keyPath = $root . '/runtime/secrets/database-encryption.key';
$databasePath = $root . '/database/config/database.json';
$environmentKey = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);

function validatedKey(string $encodedKey): string
{
    $encodedKey = trim($encodedKey);
    new DatabaseCredentialEncryption($encodedKey);
    return $encodedKey;
}

try {
    if ($environmentKey !== false && trim($environmentKey) !== '') {
        echo validatedKey($environmentKey);
        exit(0);
    }
    if (is_file($keyPath)) {
        $storedKey = @file_get_contents($keyPath);
        if ($storedKey === false) throw new RuntimeException('Unable to read the local encryption key.');
        echo validatedKey($storedKey);
        exit(0);
    }
    if (is_file($databasePath)) {
        $storedDatabase = json_decode((string)@file_get_contents($databasePath), true);
        if (is_array($storedDatabase) && DatabaseConfigurationResolver::usesEncryption($storedDatabase)) {
            throw new RuntimeException('The database configuration is encrypted, but its encryption key is unavailable.');
        }
    }
    $encodedKey = base64_encode(random_bytes(32));
    $directory = dirname($keyPath);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the local secrets directory.');
    }
    @chmod($directory, 0700);
    if (@file_put_contents($keyPath, $encodedKey . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to store the local encryption key.');
    }
    @chmod($keyPath, 0600);
    echo validatedKey($encodedKey);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
