<?php

require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/PortSelector.php';
require_once __DIR__ . '/RuntimeDetector.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

final class ApiProcessManager
{
    private AdminConfigurationRepository $configuration;
    private PortSelector $ports;
    private RuntimeDetector $runtime;
    private string $statePath;
    private string $root;

    public function __construct(
        ?AdminConfigurationRepository $configuration = null,
        ?PortSelector $ports = null,
        ?RuntimeDetector $runtime = null,
        ?string $statePath = null,
        ?string $root = null
    ) {
        $this->configuration = $configuration ?? new AdminConfigurationRepository();
        $this->ports = $ports ?? new PortSelector();
        $this->runtime = $runtime ?? new RuntimeDetector();
        $this->root = $root ?? dirname(__DIR__, 2);
        $this->statePath = $statePath ?? $this->root . '/runtime/api/api-process.json';
    }

    public function status(): array
    {
        return $this->withLock(function (): array {
            $state = $this->readState();
            if ($state === null) return $this->stopped();
            if (!$this->processExists($state['pid']) || !$this->belongsToApi($state)) {
                $this->clearState();
                return [...$this->stopped(), 'staleStateRecovered' => true];
            }
            $health = $this->health($state['port']);
            if ($health === null) {
                $this->terminate($state['pid']);
                $this->clearState();
                return [...$this->stopped(), 'crashRecovered' => true];
            }
            return [
                'running' => true,
                'healthy' => true,
                'status' => 'running',
                'port' => $state['port'],
                'pid' => $state['pid'],
                'startedAt' => $state['startedAt'],
                'uptimeSeconds' => max(0, time() - strtotime($state['startedAt'])),
                'version' => $health['version'] ?? null,
            ];
        });
    }

    public function start(): array
    {
        return $this->withLock(function (): array {
            $existing = $this->readState();
            if ($existing !== null && $this->processExists($existing['pid']) && $this->belongsToApi($existing)) {
                $health = $this->health($existing['port']);
                if ($health !== null) {
                    return [
                        'running' => true,
                        'healthy' => true,
                        'status' => 'running',
                        'port' => $existing['port'],
                        'pid' => $existing['pid'],
                        'startedAt' => $existing['startedAt'],
                        'uptimeSeconds' => max(0, time() - strtotime($existing['startedAt'])),
                        'alreadyRunning' => true,
                        'version' => $health['version'] ?? null,
                    ];
                }
                $this->terminate($existing['pid']);
            }
            if ($existing !== null) $this->clearState();

            $server = $this->configuration->load()['server'];
            $port = $this->ports->firstAvailable(
                $server['bindAddress'],
                $server['apiPortMinimum'],
                $server['apiPortMaximum'],
                [$server['adminPort']]
            );
            $startedAt = gmdate(DATE_ATOM);
            $command = $this->command($server['bindAddress'], $port);
            $directory = dirname($this->statePath);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('API runtime directory is unavailable.');
            }
            $log = $directory . '/api-server.log';
            $environment = array_merge(is_array(getenv()) ? getenv() : [], [
                'GENERIC_API_PORT' => (string)$port,
                'GENERIC_API_STARTED_AT' => $startedAt,
                'GENERIC_ADMIN_ENABLED' => '0',
            ]);
            $process = @proc_open($command, [
                0 => ['file', $this->nullDevice(), 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ], $pipes, $this->root, $environment, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new RuntimeException('API process could not be started.');
            $processStatus = proc_get_status($process);
            $pid = (int)($processStatus['pid'] ?? 0);
            if ($pid < 1) throw new RuntimeException('API process identifier is unavailable.');
            $state = ['version' => 1, 'pid' => $pid, 'port' => $port, 'startedAt' => $startedAt];
            JsonFileStore::save($this->statePath, $state);
            unset($process);

            $health = null;
            for ($attempt = 0; $attempt < 20; $attempt++) {
                usleep(100000);
                $health = $this->health($port);
                if ($health !== null) break;
                if (!$this->processExists($pid)) break;
            }
            if ($health === null) {
                $this->terminate($pid);
                $this->clearState();
                throw new RuntimeException('API process failed to become healthy.');
            }
            return [
                'running' => true,
                'healthy' => true,
                'status' => 'running',
                'port' => $port,
                'pid' => $pid,
                'startedAt' => $startedAt,
                'uptimeSeconds' => 0,
                'version' => $health['version'] ?? null,
            ];
        });
    }

    public function stop(): array
    {
        return $this->withLock(function (): array {
            $state = $this->readState();
            if ($state === null) return [...$this->stopped(), 'alreadyStopped' => true];
            if ($this->processExists($state['pid']) && $this->belongsToApi($state)) {
                $this->terminate($state['pid']);
                for ($attempt = 0; $attempt < 30 && $this->processExists($state['pid']); $attempt++) {
                    usleep(100000);
                }
                if ($this->processExists($state['pid'])) {
                    $this->forceTerminate($state['pid']);
                }
            }
            $this->clearState();
            return $this->stopped();
        });
    }

    public function restart(): array
    {
        $this->stop();
        return $this->start();
    }

    private function command(string $address, int $port): array
    {
        $command = [$this->runtime->runtimeBinary()];
        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') array_push($command, '-c', $ini);
        array_push(
            $command,
            '-d', 'error_log=' . $this->root . '/logs/php_errors.log',
            '-S', $address . ':' . $port,
            '-t', $this->root . '/api',
            $this->root . '/api/router.php'
        );
        return $command;
    }

    private function health(int $port): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 0.25, 'ignore_errors' => true]]);
        $json = @file_get_contents("http://127.0.0.1:{$port}/health", false, $context);
        if (!is_string($json)) return null;
        try {
            $health = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return null;
        }
        return is_array($health) && ($health['status'] ?? null) === 'healthy'
            && ($health['port'] ?? null) === $port ? $health : null;
    }

    private function readState(): ?array
    {
        if (!is_file($this->statePath)) return null;
        try {
            $state = JsonFileStore::load($this->statePath);
        } catch (Throwable $exception) {
            $this->clearState();
            return null;
        }
        if (array_keys($state) !== ['version', 'pid', 'port', 'startedAt']
            || ($state['version'] ?? null) !== 1
            || !is_int($state['pid'] ?? null) || $state['pid'] < 1
            || !is_int($state['port'] ?? null) || $state['port'] < 1 || $state['port'] > 65535
            || !is_string($state['startedAt'] ?? null) || strtotime($state['startedAt']) === false) {
            $this->clearState();
            return null;
        }
        return $state;
    }

    private function belongsToApi(array $state): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            $command = 'powershell.exe -NoProfile -NonInteractive -Command '
                . escapeshellarg("Get-CimInstance Win32_Process -Filter 'ProcessId = {$state['pid']}' | Select-Object -ExpandProperty CommandLine");
            exec($command, $output, $code);
            if ($code !== 0) return false;
            $commandLine = str_replace('\\', '/', implode("\n", $output));
        } else {
            $commandLine = @file_get_contents('/proc/' . $state['pid'] . '/cmdline');
        }
        $router = str_replace('\\', '/', $this->root . '/api/router.php');
        return is_string($commandLine)
            && str_contains(str_replace('\\', '/', $commandLine), $router)
            && str_contains($commandLine, '127.0.0.1:' . $state['port']);
    }

    private function processExists(int $pid): bool
    {
        if ($pid < 1) return false;
        if (PHP_OS_FAMILY === 'Windows') {
            exec('tasklist /FI "PID eq ' . $pid . '" /NH', $output, $code);
            return $code === 0 && str_contains(implode("\n", $output), (string)$pid);
        }
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir('/proc/' . $pid);
    }

    private function terminate(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /PID ' . $pid . ' /T', $output, $code);
            return;
        }
        if (function_exists('posix_kill')) @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
    }

    private function forceTerminate(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /PID ' . $pid . ' /T', $output, $code);
            return;
        }
        if (function_exists('posix_kill')) @posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
    }

    private function withLock(callable $operation)
    {
        $directory = dirname($this->statePath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('API runtime directory is unavailable.');
        }
        $lock = @fopen($this->statePath . '.lock', 'c');
        if ($lock === false) throw new RuntimeException('API process lock is unavailable.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('API process lock could not be acquired.');
            return $operation();
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function clearState(): void
    {
        if (is_file($this->statePath)) @unlink($this->statePath);
    }

    private function stopped(): array
    {
        return [
            'running' => false,
            'healthy' => false,
            'status' => 'stopped',
            'port' => null,
            'pid' => null,
            'startedAt' => null,
            'uptimeSeconds' => null,
            'version' => null,
        ];
    }

    private function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}
