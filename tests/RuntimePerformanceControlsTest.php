<?php

require_once __DIR__ . '/../app/Configuration/RuntimeControls.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Requests/SqlRequestValidator.php';
require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Security/ApiRateLimiter.php';
require_once __DIR__ . '/../app/Security/LoginRateLimiter.php';
require_once __DIR__ . '/../app/Middleware/ApiRateLimitMiddleware.php';
require_once __DIR__ . '/../app/Http/RequestBodyReader.php';
require_once __DIR__ . '/../app/Authorization/Principal.php';
require_once __DIR__ . '/../app/Authorization/PrincipalContext.php';
require_once __DIR__ . '/../core/QueryEngine.php';

function runtimeControlsAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function runtimeControlsFailure(callable $operation, string $code): ApiRequestException
{
    try {
        $operation();
    } catch (ApiRequestException $exception) {
        runtimeControlsAssert($exception->getErrorCode() === $code, "Unexpected error code {$exception->getErrorCode()}.");
        return $exception;
    }
    throw new RuntimeException("Expected {$code} failure.");
}

function removeRuntimeControlsDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

final class ConfiguredTimeoutEngine extends QueryEngine
{
    public function __construct() { parent::__construct(null, new Logger(sys_get_temp_dir()), null, false); }
    public function timeoutSeconds(): int { return $this->queryTimeoutSeconds; }
}

$directory = sys_get_temp_dir() . '/generic-runtime-controls-' . bin2hex(random_bytes(8));
$configurationPath = $directory . '/admin.json';
$oldConfigurationPath = getenv('GENERIC_ADMIN_CONFIG_PATH');
$oldQueryTimeout = getenv('DB_QUERY_TIMEOUT_SECONDS');
$oldIdleTimeout = getenv('GENERIC_SESSION_IDLE_TIMEOUT');
$oldAbsoluteTimeout = getenv('GENERIC_SESSION_ABSOLUTE_TIMEOUT');
$oldRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    mkdir($directory, 0700, true);
    $repository = new AdminConfigurationRepository($configurationPath);
    $repository->save(AdminConfigurationRepository::defaults());
    putenv('GENERIC_ADMIN_CONFIG_PATH=' . $configurationPath);
    putenv('DB_QUERY_TIMEOUT_SECONDS');
    putenv('GENERIC_SESSION_IDLE_TIMEOUT');
    putenv('GENERIC_SESSION_ABSOLUTE_TIMEOUT');

    $defaults = RuntimeControls::defaults();
    runtimeControlsAssert(SecurityConfiguration::queryTimeoutSeconds() === 45, 'Default query timeout changed.');
    runtimeControlsAssert((new ConfiguredTimeoutEngine())->timeoutSeconds() === 45, 'Query engine did not read the runtime timeout.');

    $validator = new AdminRequestValidator();
    $minimum = $defaults;
    $minimum['query']['timeoutSeconds'] = RuntimeControls::QUERY_TIMEOUT_MINIMUM;
    $minimum['request']['defaultPageSize'] = 1;
    $minimum['request']['maxPageSize'] = 1;
    $validated = $validator->validate(['action' => 'admin.runtime.save', 'runtime' => $minimum]);
    (new AdminService($repository, $directory . '/database.json'))->saveRuntime($validated['runtime']);
    runtimeControlsAssert(SecurityConfiguration::queryTimeoutSeconds() === 1, 'Minimum query timeout was not applied immediately.');
    runtimeControlsAssert((new ConfiguredTimeoutEngine())->timeoutSeconds() === 1, 'Configured query timeout did not reach SQL execution.');

    $maximum = $defaults;
    $maximum['query']['timeoutSeconds'] = RuntimeControls::QUERY_TIMEOUT_MAXIMUM;
    $maximum['request']['defaultPageSize'] = RuntimeControls::PAGE_SIZE_MAXIMUM;
    $maximum['request']['maxPageSize'] = RuntimeControls::PAGE_SIZE_MAXIMUM;
    $repository->save([...$repository->load(), 'runtime' => $maximum]);
    runtimeControlsAssert(SecurityConfiguration::queryTimeoutSeconds() === 300, 'Maximum query timeout was rejected.');
    runtimeControlsFailure(
        fn () => $validator->validate(['action' => 'admin.runtime.save', 'runtime' => [...$defaults, 'query' => ['timeoutSeconds' => 0]]]),
        'INVALID_ADMIN_REQUEST'
    );
    $invalidRelation = $defaults;
    $invalidRelation['session']['idleTimeoutSeconds'] = 3600;
    $invalidRelation['session']['absoluteTimeoutSeconds'] = 1800;
    runtimeControlsFailure(fn () => $validator->validate(['action' => 'admin.runtime.save', 'runtime' => $invalidRelation]), 'INVALID_ADMIN_REQUEST');

    $before = $repository->load();
    try {
        $repository->update(function (array &$configuration): void {
            $configuration['runtime']['query']['timeoutSeconds'] = -1;
        });
        throw new RuntimeException('Invalid atomic update was accepted.');
    } catch (RuntimeException $exception) {
        runtimeControlsAssert($repository->load() === $before, 'Failed runtime save replaced the prior valid configuration.');
    }

    $paginationRuntime = $defaults;
    $paginationRuntime['request']['defaultPageSize'] = 37;
    $paginationRuntime['request']['maxPageSize'] = 50;
    $repository->save([...$repository->load(), 'runtime' => $paginationRuntime]);
    $sqlRequest = ['action' => 'sql', 'resource' => 'sales', 'pagination' => ['page' => 2]];
    (new SqlRequestValidator())->validate($sqlRequest);
    $normalized = (new QueryRequestNormalizer())->normalize($sqlRequest);
    runtimeControlsAssert($normalized['pagination'] === ['page' => 2, 'pageSize' => 37], 'Configured default page size was not applied.');
    runtimeControlsFailure(
        fn () => (new SqlRequestValidator())->validate(['action' => 'sql', 'resource' => 'sales', 'pagination' => ['page' => 1, 'pageSize' => 51]]),
        'INVALID_REQUEST'
    );
    (new QueryRequestValidator())->validate([
        'action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['Id'],
        'pagination' => ['page' => 1, 'pageSize' => 50],
    ]);

    $smallBodyRuntime = $paginationRuntime;
    $smallBodyRuntime['request']['maxBodyBytes'] = 1024;
    $repository->save([...$repository->load(), 'runtime' => $smallBodyRuntime]);
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, str_repeat('a', 1024));
    rewind($stream);
    runtimeControlsAssert(strlen(RequestBodyReader::read(1024, $stream)) === 1024, 'Boundary-size request body was rejected.');
    fclose($stream);
    runtimeControlsFailure(fn () => RequestBodyReader::read(1025, fopen('php://temp', 'w+b')), 'REQUEST_TOO_LARGE');

    $now = 1000;
    $apiDirectory = $directory . '/api-rate';
    $limiter = new ApiRateLimiter($apiDirectory, ['enabled' => true, 'requests' => 2, 'windowSeconds' => 10], function () use (&$now): int { return $now; });
    $limiter->consume('anonymous:192.0.2.10');
    $limiter->consume('anonymous:192.0.2.10');
    $limited = runtimeControlsFailure(fn () => $limiter->consume('anonymous:192.0.2.10'), 'RATE_LIMIT_EXCEEDED');
    runtimeControlsAssert($limited->getStatusCode() === 429 && $limited->getDetails() === [], 'API rate-limit contract changed.');
    $now = 1010;
    $limiter->consume('anonymous:192.0.2.10');
    (new ApiRateLimiter($directory . '/disabled-rate', ['enabled' => false, 'requests' => 1, 'windowSeconds' => 1]))->consume('anonymous:any');

    $identityLimiter = new ApiRateLimiter($directory . '/identity-rate', ['enabled' => true, 'requests' => 1, 'windowSeconds' => 60]);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
    PrincipalContext::clear();
    (new ApiRateLimitMiddleware($identityLimiter))->handle(['action' => 'auth.session']);
    runtimeControlsFailure(fn () => (new ApiRateLimitMiddleware($identityLimiter))->handle(['action' => 'auth.session']), 'RATE_LIMIT_EXCEEDED');
    PrincipalContext::set(new Principal(str_repeat('a', 32), 'Session.User', 'session', 'read-only', false, null, true));
    (new ApiRateLimitMiddleware($identityLimiter))->handle(['action' => 'select']);
    runtimeControlsFailure(fn () => (new ApiRateLimitMiddleware($identityLimiter))->handle(['action' => 'select']), 'RATE_LIMIT_EXCEEDED');
    $_SERVER['HTTP_X_API_KEY'] = str_repeat('k', 32);
    PrincipalContext::set(new Principal(null, 'legacy-api-key', 'api_key', 'read-only', false, null, true));
    (new ApiRateLimitMiddleware($identityLimiter))->handle(['action' => 'select']);
    runtimeControlsFailure(fn () => (new ApiRateLimitMiddleware($identityLimiter))->handle(['action' => 'select']), 'RATE_LIMIT_EXCEEDED');
    PrincipalContext::clear();
    unset($_SERVER['HTTP_X_API_KEY']);

    $loginNow = 2000;
    $loginLimiter = new LoginRateLimiter(
        $directory . '/login-rate',
        ['enabled' => true, 'maximumAttempts' => 2, 'windowSeconds' => 60, 'lockoutSeconds' => 30],
        function () use (&$loginNow): int { return $loginNow; }
    );
    runtimeControlsAssert($loginLimiter->recordFailure('192.0.2.1', 'user') === false, 'Login limiter blocked too early.');
    runtimeControlsAssert($loginLimiter->recordFailure('192.0.2.1', 'user') === true, 'Configured login threshold was not enforced.');
    runtimeControlsFailure(fn () => $loginLimiter->assertAllowed('192.0.2.1', 'user'), 'LOGIN_RATE_LIMITED');
    $loginNow = 2031;
    $loginLimiter->assertAllowed('192.0.2.1', 'user');
    $loginLimiter->reset('192.0.2.1', 'user');
    (new LoginRateLimiter($directory . '/login-disabled', ['enabled' => false]))->recordFailure('ip', 'user');

    $sessionRuntime = $defaults;
    $sessionRuntime['session'] = ['idleTimeoutSeconds' => 600, 'absoluteTimeoutSeconds' => 3600];
    $repository->save([...$repository->load(), 'runtime' => $sessionRuntime]);
    $sessionOptions = SecurityConfiguration::sessionOptions();
    runtimeControlsAssert($sessionOptions['idleTimeout'] === 600 && $sessionOptions['absoluteTimeout'] === 3600, 'Session thresholds did not use runtime configuration.');

    $settings = (new AdminService($repository, $directory . '/database.json'))->settings();
    runtimeControlsAssert($settings['runtime'] === $sessionRuntime, 'Admin settings did not return runtime controls.');
    $encoded = json_encode($settings, JSON_THROW_ON_ERROR);
    foreach (['passwordHash', 'apiKeyHash', 'sessionId', 'ciphertext'] as $secret) {
        runtimeControlsAssert(!str_contains($encoded, $secret), "Runtime settings exposed {$secret}.");
    }
    runtimeControlsAssert(
        str_contains((string)file_get_contents(__DIR__ . '/../admin/api.php'), "'admin.runtime.save'")
            && str_contains((string)file_get_contents(__DIR__ . '/../admin/api.php'), 'AdminAuthorizationMiddleware'),
        'Runtime save is not protected by backend administrator authorization.'
    );

    echo "Runtime and performance control tests passed.\n";
} finally {
    PrincipalContext::clear();
    unset($_SERVER['HTTP_X_API_KEY']);
    if ($oldRemoteAddress === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldRemoteAddress;
    $restore = static fn (string $name, $value) => $value === false ? putenv($name) : putenv($name . '=' . $value);
    $restore('GENERIC_ADMIN_CONFIG_PATH', $oldConfigurationPath);
    $restore('DB_QUERY_TIMEOUT_SECONDS', $oldQueryTimeout);
    $restore('GENERIC_SESSION_IDLE_TIMEOUT', $oldIdleTimeout);
    $restore('GENERIC_SESSION_ABSOLUTE_TIMEOUT', $oldAbsoluteTimeout);
    removeRuntimeControlsDirectory($directory);
}
