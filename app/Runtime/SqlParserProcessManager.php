<?php

require_once __DIR__ . '/ApiProcessManager.php';

final class SqlParserProcessManager extends ApiProcessManager
{
    public function __construct(
        ?AdminConfigurationRepository $configuration = null,
        ?PortSelector $ports = null,
        ?RuntimeDetector $runtime = null,
        ?string $statePath = null,
        ?string $root = null
    ) {
        parent::__construct($configuration, $ports, $runtime, $statePath, $root, 'sqlparser');
    }
}
