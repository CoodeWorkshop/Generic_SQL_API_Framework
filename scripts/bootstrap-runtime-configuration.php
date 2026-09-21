<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';

try {
    $result = RuntimeConfiguration::ensure();
    echo 'READY';
    if ($result['created'] !== []) {
        echo ' created=' . implode(',', $result['created']);
    }
    echo PHP_EOL;
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    $reason = str_contains($message, 'directory') ? 'CONFIG_DIRECTORY_UNAVAILABLE'
        : (str_contains($message, 'lock') ? 'CONFIG_LOCK_UNAVAILABLE'
        : (str_contains($message, 'JSON') ? 'CONFIG_JSON_INVALID' : 'CONFIG_WRITE_FAILED'));
    fwrite(STDERR, '[FAILED] Runtime configuration could not be initialized. Reason: ' . $reason . PHP_EOL);
    exit(1);
}
