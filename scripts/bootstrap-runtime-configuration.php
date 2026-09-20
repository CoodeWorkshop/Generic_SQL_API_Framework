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
    fwrite(STDERR, '[FAILED] Runtime configuration could not be initialized.' . PHP_EOL);
    exit(1);
}
