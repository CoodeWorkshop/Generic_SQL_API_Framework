<?php

require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';

$operation = $argv[1] ?? '';
if ($operation !== 'disconnect') {
    fwrite(STDERR, '[FAILED] Expected disconnect.' . PHP_EOL);
    exit(2);
}

try {
    $result = (new DatabaseAvailabilityManager())->setAvailable(false);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] Database runtime state could not be reset.' . PHP_EOL);
    exit(1);
}
