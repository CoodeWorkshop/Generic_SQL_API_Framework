<?php

require_once __DIR__ . '/../app/Deployment/ProductionValidator.php';

try {
    echo json_encode((new ProductionValidator())->report(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, "Production validation failed: {$exception->getMessage()}\n");
    exit(1);
}
