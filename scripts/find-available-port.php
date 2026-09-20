<?php

require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Runtime/PortSelector.php';

try {
    $server = (new AdminConfigurationRepository())->load()['server'];
    $selector = new PortSelector();
    $mode = $argv[1] ?? 'api';
    if ($mode === 'admin') {
        if (!$selector->isAvailable($server['bindAddress'], $server['adminPort'])) {
            throw new RuntimeException('The configured Admin port is already in use.');
        }
        echo $server['adminPort'];
        exit(0);
    }
    if ($mode !== 'api') throw new InvalidArgumentException('Unsupported port selection mode.');
    echo $selector->firstAvailable(
        $server['bindAddress'],
        $server['apiPortMinimum'],
        $server['apiPortMaximum'],
        [$server['adminPort']]
    );
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
