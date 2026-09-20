<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';

final class RuntimeDetector
{
    public function information(): array
    {
        $family = PHP_OS_FAMILY;
        $binary = $this->runtimeBinary($family);
        $application = require ROOT_PATH . '/config/app.php';
        $performance = require ROOT_PATH . '/config/performance.php';
        return [
            'operatingSystem' => $family,
            'architecture' => php_uname('m'),
            'phpVersion' => PHP_VERSION,
            'phpRuntime' => str_starts_with($binary, ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime') ? 'Bundled PHP' : 'System PHP',
            'runtimePath' => $this->displayPath($binary),
            'frameworkVersion' => (string)($application['version'] ?? 'unknown'),
            'apiVersion' => (string)($application['version'] ?? 'unknown'),
            'adminConsoleVersion' => '2.1',
            'odbcAvailable' => extension_loaded('odbc'),
            'pdoOdbcAvailable' => extension_loaded('pdo_odbc'),
            'supportedSqlServerDrivers' => SqlServerDriver::supportedDrivers(),
            'queryTimeoutSeconds' => (int)$performance['database_query_timeout_seconds'],
            'debugMode' => ($application['debug'] ?? false) === true,
        ];
    }

    public function runtimeBinary(?string $family = null): string
    {
        $family ??= PHP_OS_FAMILY;
        $candidate = $family === 'Windows'
            ? ROOT_PATH . '/runtime/windows/php/php.exe'
            : ROOT_PATH . '/runtime/linux/php/php';
        return is_file($candidate) && is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    private function displayPath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', ROOT_PATH), '/');
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $root . '/')
            ? substr($normalized, strlen($root) + 1)
            : $normalized;
    }
}
