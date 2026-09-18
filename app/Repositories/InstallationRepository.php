<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

final class InstallationRepository
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? ROOT_PATH . '/config/installation.json';
    }

    public function load(): array
    {
        $configuration = JsonFileStore::load($this->path);
        $this->validate($configuration);
        return $configuration;
    }

    public function save(array $configuration): void
    {
        $this->validate($configuration);
        JsonFileStore::save($this->path, $configuration);
    }

    private function validate(array $configuration): void
    {
        if (array_diff(array_keys($configuration), ['version', 'installationId', 'initialized']) !== []
            || ($configuration['version'] ?? null) !== 1
            || !is_string($configuration['installationId'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $configuration['installationId']) !== 1
            || !is_bool($configuration['initialized'] ?? null)) {
            throw new RuntimeException('Invalid installation configuration.');
        }
    }
}
