<?php

require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Runtime/PortSelector.php';
require_once __DIR__ . '/../app/Runtime/ApiProcessManager.php';
require_once __DIR__ . '/../app/Runtime/RuntimeDetector.php';
require_once __DIR__ . '/../app/Middleware/FeatureAccessMiddleware.php';
require_once __DIR__ . '/../app/Services/AdminService.php';

function runtimeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function runtimeFailure(callable $operation, string $message, ?string $code = null): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($code !== null) runtimeAssert($exception instanceof ApiRequestException && $exception->getErrorCode() === $code, $message);
        return;
    }
    throw new RuntimeException($message);
}

$directory = sys_get_temp_dir() . '/generic-api-runtime-' . bin2hex(random_bytes(6));
$configurationPath = $directory . '/admin.json';
$statePath = $directory . '/api-process.json';
$oldConfigurationPath = getenv('GENERIC_ADMIN_CONFIG_PATH');
$manager = null;

try {
    mkdir($directory, 0700, true);
    $validator = new AdminRequestValidator();
    $validServer = $validator->validate([
        'action' => 'admin.server.save',
        'server' => [
            'apiPortMinimum' => 18120,
            'apiPortMaximum' => 18125,
            'adminPort' => 18123,
            'bindAddress' => '127.0.0.1',
        ],
    ])['server'];
    runtimeAssert($validServer['apiPortMinimum'] === 18120, 'Valid port range was not normalized.');
    runtimeFailure(fn () => $validator->validate([
        'action' => 'admin.server.save',
        'server' => [...$validServer, 'apiPortMinimum' => 18130],
    ]), 'Minimum greater than maximum was accepted.');
    runtimeFailure(fn () => $validator->validate([
        'action' => 'admin.server.save',
        'server' => [...$validServer, 'bindAddress' => '0.0.0.0'],
    ]), 'Remote bind address was accepted.');

    $probed = [];
    $selector = new PortSelector(function (string $address, int $port) use (&$probed): bool {
        $probed[] = [$address, $port];
        return $port === 18121;
    });
    runtimeAssert($selector->firstAvailable('127.0.0.1', 18120, 18122) === 18121, 'Occupied first port did not fall back.');
    runtimeAssert(count($probed) === 2, 'Port range was not searched in order.');
    runtimeFailure(
        fn () => (new PortSelector(fn (): bool => false))->firstAvailable('127.0.0.1', 18120, 18122),
        'Exhausted port range was accepted.'
    );

    $repository = new AdminConfigurationRepository($configurationPath);
    JsonFileStore::save($configurationPath, [
        'version' => 1,
        'cors' => AdminConfigurationRepository::defaults()['cors'],
        'authentication' => ['mode' => 'none'],
    ]);
    $migrated = $repository->load();
    runtimeAssert(
        $migrated['version'] === 2
            && $migrated['authentication']['mode'] === 'none'
            && isset($migrated['server'], $migrated['features']),
        'Version-1 Admin configuration was not safely migrated.'
    );
    $configuration = AdminConfigurationRepository::defaults();
    $configuration['server'] = $validServer;
    $repository->save($configuration);
    putenv('GENERIC_ADMIN_CONFIG_PATH=' . $configurationPath);

    $features = $validator->validate([
        'action' => 'admin.features.save',
        'features' => [
            'metadata' => false,
            'sorting' => false,
            'pagination' => false,
            'writeData' => false,
            'readData' => false,
        ],
    ])['features'];
    $configuration['features'] = $features;
    $repository->save($configuration);
    $middleware = new FeatureAccessMiddleware($repository);
    foreach (['select', 'sql', 'union', 'procedure', 'function', 'tableFunction'] as $action) {
        runtimeFailure(fn () => $middleware->handle(['action' => $action]), 'Read Data was not enforced.', 'FEATURE_DISABLED');
    }
    foreach (['insert', 'update', 'delete', 'upsert'] as $action) {
        runtimeFailure(fn () => $middleware->handle(['action' => $action]), 'Write Data was not enforced.', 'FEATURE_DISABLED');
    }
    runtimeFailure(fn () => $middleware->handle(['action' => 'metadata.tables']), 'Metadata was not enforced.', 'FEATURE_DISABLED');
    $configuration['features'] = array_fill_keys(array_keys($features), true);
    $configuration['features']['pagination'] = false;
    $configuration['features']['sorting'] = false;
    $repository->save($configuration);
    runtimeFailure(fn () => $middleware->handle(['action' => 'select', 'pagination' => ['page' => 1]]), 'Pagination was not enforced.', 'FEATURE_DISABLED');
    runtimeFailure(fn () => $middleware->handle(['action' => 'union', 'queries' => [['sort' => []]]]), 'Nested sorting was not enforced.', 'FEATURE_DISABLED');
    $middleware->handle(['action' => 'select']);

    $runtime = (new RuntimeDetector())->information();
    runtimeAssert($runtime['operatingSystem'] !== '' && $runtime['phpVersion'] === PHP_VERSION, 'Runtime detection is incomplete.');
    runtimeAssert($runtime['runtimePath'] !== '', 'PHP runtime path was not detected.');

    $realSelector = new PortSelector();
    $first = $realSelector->firstAvailable('127.0.0.1', 18200, 18300);
    $configuration['server'] = [
        'apiPortMinimum' => $first,
        'apiPortMaximum' => min(65535, $first + 2),
        'adminPort' => $first + 1,
        'bindAddress' => '127.0.0.1',
    ];
    $repository->save($configuration);
    $manager = new ApiProcessManager($repository, null, null, $statePath, dirname(__DIR__));
    runtimeAssert($manager->status()['running'] === false, 'Missing PID state was not stopped.');
    $started = $manager->start();
    runtimeAssert($started['running'] && $started['healthy'] && $started['port'] !== $configuration['server']['adminPort'], 'API did not start on a healthy non-admin port.');
    $adminService = new AdminService($repository, $directory . '/database.json', static function (): void {}, $manager);
    runtimeFailure(
        fn () => $adminService->saveServer([...$configuration['server'], 'adminPort' => $started['port']]),
        'Running API/Admin port conflict was accepted.',
        'INVALID_ADMIN_REQUEST'
    );
    $again = $manager->start();
    runtimeAssert(($again['alreadyRunning'] ?? false) === true && $again['pid'] === $started['pid'], 'Duplicate API process was started.');
    $restarted = $manager->restart();
    runtimeAssert($restarted['running'] && $restarted['healthy'], 'API restart failed.');
    if (PHP_OS_FAMILY === 'Linux' && function_exists('posix_kill')) {
        @posix_kill($restarted['pid'], defined('SIGKILL') ? SIGKILL : 9);
        usleep(200000);
        $crashed = $manager->status();
        runtimeAssert(!$crashed['running'], 'Crashed API process was not recovered.');
        runtimeAssert($manager->start()['healthy'] === true, 'API could not start after crash recovery.');
    }
    runtimeAssert($manager->stop()['running'] === false, 'API stop failed.');
    runtimeAssert(($manager->stop()['alreadyStopped'] ?? false) === true, 'Already-stopped API was not idempotent.');
    JsonFileStore::save($statePath, ['version' => 1, 'pid' => getmypid(), 'port' => $first, 'startedAt' => gmdate(DATE_ATOM)]);
    $stale = $manager->status();
    runtimeAssert(!$stale['running'] && ($stale['staleStateRecovered'] ?? false), 'Stale or foreign PID was not recovered.');

    $adminHtml = (string)file_get_contents(__DIR__ . '/../admin/index.php');
    runtimeAssert(str_contains($adminHtml, 'id="navigation" hidden'), 'Unauthenticated navigation is not hidden.');
    foreach (['System Health', 'System Info', 'Configuration', 'Users'] as $label) {
        runtimeAssert(str_contains($adminHtml, $label), "Authenticated navigation is missing {$label}.");
    }
    runtimeAssert(!str_contains($adminHtml, 'SQL parser'), 'SQL Parser remains coupled to Admin navigation.');
    $adminServiceSource = (string)file_get_contents(__DIR__ . '/../app/Services/AdminService.php');
    runtimeAssert(!str_contains($adminServiceSource, 'SqlGenerator'), 'Admin service remains coupled to SQL Parser.');
    $parserIndex = (string)file_get_contents(__DIR__ . '/../sqlparser/index.php');
    runtimeAssert(!str_contains($parserIndex, 'Admin') && !str_contains($parserIndex, 'Database'), 'SQL Parser entry point depends on Admin or Database.');
    $windowsLauncher = (string)file_get_contents(__DIR__ . '/../start-windows.bat');
    $linuxLauncher = (string)file_get_contents(__DIR__ . '/../start-linux.sh');
    runtimeAssert(str_contains($windowsLauncher, 'runtime\windows\php\php.exe'), 'Windows runtime is not automatically selected.');
    runtimeAssert(str_contains($linuxLauncher, 'runtime/linux/php/php'), 'Linux bundled runtime is not preferred.');
    foreach ([$windowsLauncher, $linuxLauncher] as $launcher) {
        runtimeAssert(str_contains($launcher, 'api-runtime-control.php'), 'Launcher does not start the managed API lifecycle.');
        runtimeAssert(str_contains($launcher, '-t') && str_contains($launcher, 'router.php'), 'Launcher does not start the independent Admin app.');
    }

    echo "Admin runtime management tests passed.\n";
} finally {
    if ($manager instanceof ApiProcessManager) {
        try { $manager->stop(); } catch (Throwable $exception) {}
    }
    foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
    @rmdir($directory);
    $oldConfigurationPath === false
        ? putenv('GENERIC_ADMIN_CONFIG_PATH')
        : putenv('GENERIC_ADMIN_CONFIG_PATH=' . $oldConfigurationPath);
}
