<?php

require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Middleware/LocalAdminMiddleware.php';
require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';
require_once __DIR__ . '/../app/Security/SecurityConfiguration.php';
require_once __DIR__ . '/../app/Security/ApiKeyAuthenticator.php';

function unifiedAdminAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function unifiedAdminFailure(callable $operation, string $message, ?string $errorCode = null): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($errorCode !== null) {
            unifiedAdminAssert(
                $exception instanceof ApiRequestException && $exception->getErrorCode() === $errorCode,
                $message . ' Wrong error was returned.'
            );
        }
        return;
    }
    throw new RuntimeException($message);
}

$temporaryDirectory = sys_get_temp_dir() . '/generic-sql-admin-' . bin2hex(random_bytes(8));
$adminPath = $temporaryDirectory . '/admin.json';
$databasePath = $temporaryDirectory . '/database.json';
$authPath = $temporaryDirectory . '/auth.json';
$sessionPath = $temporaryDirectory . '/sessions';
$oldAdminPath = getenv('GENERIC_ADMIN_CONFIG_PATH');
$oldEncryptionKey = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
$oldApiKey = getenv(ApiKeyAuthenticator::ENVIRONMENT_VARIABLE);
$oldAdminEnabled = getenv('GENERIC_ADMIN_ENABLED');
$oldOriginOverride = getenv('GENERIC_API_ALLOWED_ORIGINS');

try {
    mkdir($temporaryDirectory, 0700, true);
    mkdir($sessionPath, 0700, true);
    $repository = new AdminConfigurationRepository($adminPath);
    $repository->save(AdminConfigurationRepository::defaults());
    putenv('GENERIC_ADMIN_CONFIG_PATH=' . $adminPath);
    putenv('GENERIC_API_ALLOWED_ORIGINS');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));

    $validator = new AdminRequestValidator();
    $corsRequest = $validator->validate([
        'action' => 'admin.cors.save',
        'cors' => [
            'allowedOrigins' => ['https://reports.example.test:8443'],
            'credentialsEnabled' => false,
            'allowedMethods' => ['POST', 'OPTIONS'],
        ],
    ]);
    unifiedAdminAssert(
        $corsRequest['cors']['allowedOrigins'] === ['https://reports.example.test:8443'],
        'Exact CORS origin was not preserved.'
    );
    unifiedAdminFailure(fn () => $validator->validate([
        'action' => 'admin.cors.save',
        'cors' => ['allowedOrigins' => ['*'], 'credentialsEnabled' => true, 'allowedMethods' => ['POST']],
    ]), 'Wildcard CORS origin was accepted.');
    unifiedAdminFailure(fn () => $validator->validate([
        'action' => 'admin.cors.save',
        'cors' => ['allowedOrigins' => ['https://example.test/path'], 'credentialsEnabled' => true, 'allowedMethods' => ['POST']],
    ]), 'CORS origin with a path was accepted.');

    $validDatabase = [
        'provider' => 'sqlserver',
        'driver' => 'auto',
        'server' => 'sql.example.test',
        'port' => '1433',
        'database' => 'Reporting',
        'authentication' => 'sql',
        'username' => 'reporting_user',
        'password' => 'first-secret',
        'encrypt' => true,
        'trustServerCertificate' => false,
    ];
    $normalizedDatabase = $validator->validate([
        'action' => 'admin.database.save',
        'database' => $validDatabase,
    ])['database'];
    unifiedAdminFailure(fn () => $validator->validate([
        'action' => 'admin.database.save',
        'database' => [...$validDatabase, 'server' => 'server;UID=attacker'],
    ]), 'Connection-string injection was accepted.');

    $connectionTests = 0;
    $testedDatabase = null;
    $service = new AdminService(
        $repository,
        $databasePath,
        function (array $database) use (&$connectionTests, &$testedDatabase): void {
            $connectionTests++;
            $testedDatabase = $database;
        }
    );
    $service->testDatabase($normalizedDatabase);
    unifiedAdminAssert($connectionTests === 1 && $testedDatabase['password'] === 'first-secret', 'Connection test did not receive validated credentials.');
    $failingService = new AdminService($repository, $databasePath, function (): void {
        throw new RuntimeException('driver leaked password=do-not-return');
    });
    try {
        $failingService->testDatabase($normalizedDatabase);
        throw new RuntimeException('Failed connection test was accepted.');
    } catch (ApiRequestException $exception) {
        unifiedAdminAssert($exception->getMessage() === 'Database connection failed.', 'Connection failure exposed driver details.');
    }
    $service->saveDatabase($normalizedDatabase);
    $stored = json_decode((string)file_get_contents($databasePath), true, 512, JSON_THROW_ON_ERROR);
    unifiedAdminAssert(($stored['encrypted'] ?? false) === true, 'Database configuration was not encrypted.');
    unifiedAdminAssert(!str_contains((string)file_get_contents($databasePath), 'first-secret'), 'Plaintext password reached database storage.');
    unifiedAdminAssert(glob($databasePath . '.backup*') === [], 'A plaintext backup artifact was created.');
    $publicDatabase = $service->databaseConfiguration();
    unifiedAdminAssert(!array_key_exists('password', $publicDatabase), 'Database password was returned by the admin API.');
    unifiedAdminAssert(!array_key_exists('ciphertext', $publicDatabase), 'Encrypted credential material was returned by the admin API.');

    $updatedDatabase = $normalizedDatabase;
    $updatedDatabase['password'] = 'second-secret';
    $service->saveDatabase($updatedDatabase);
    unifiedAdminAssert(
        DatabaseConfigurationResolver::load($databasePath)['password'] === 'second-secret',
        'Database password update was not persisted.'
    );

    foreach (AdminConfigurationRepository::AUTHENTICATION_MODES as $mode) {
        $service->saveAuthentication($mode);
        unifiedAdminAssert((new AdminConfigurationRepository($adminPath))->load()['authentication']['mode'] === $mode, "Authentication mode {$mode} was not persisted.");
    }
    $service->saveCors($corsRequest['cors']);
    unifiedAdminAssert(SecurityConfiguration::allowedOrigins() === ['https://reports.example.test:8443'], 'Persisted CORS origins were not authoritative.');
    unifiedAdminAssert(SecurityConfiguration::corsCredentialsEnabled() === false, 'CORS credentials setting was not enforced.');

    putenv(ApiKeyAuthenticator::ENVIRONMENT_VARIABLE . '=' . str_repeat('k', 32));
    $apiKeys = new ApiKeyAuthenticator();
    unifiedAdminAssert($apiKeys->authenticate(str_repeat('k', 32)), 'Configured API key was rejected.');
    unifiedAdminAssert(!$apiKeys->authenticate(str_repeat('x', 32)), 'Incorrect API key was accepted.');

    JsonFileStore::save($authPath, ['version' => 1, 'users' => []]);
    ini_set('session.save_path', $sessionPath);
    $session = new AuthSessionService('generic_admin_test_' . bin2hex(random_bytes(4)));
    $authentication = new AuthenticationMiddleware(true, [], $session, new AuthRepository($authPath), $apiKeys);
    $service->saveAuthentication('none');
    $authentication->handle(['action' => 'select']);
    (new CsrfProtectionMiddleware())->handle(['action' => 'insert']);
    $service->saveAuthentication('api_key');
    $_SERVER['HTTP_X_API_KEY'] = str_repeat('k', 32);
    $authentication->handle(['action' => 'select']);
    unifiedAdminAssert(($_SERVER['GENERIC_AUTH_PROVIDER'] ?? null) === 'api_key', 'API-key provider was not recorded.');
    unset($_SERVER['GENERIC_AUTH_PROVIDER']);
    $_SERVER['HTTP_X_API_KEY'] = str_repeat('x', 32);
    unifiedAdminFailure(fn () => $authentication->handle(['action' => 'select']), 'Invalid API key was accepted.', 'AUTHENTICATION_REQUIRED');
    $service->saveAuthentication('session+api_key');
    $_SERVER['HTTP_X_API_KEY'] = str_repeat('k', 32);
    $authentication->handle(['action' => 'select']);
    unset($_SERVER['HTTP_X_API_KEY'], $_SERVER['GENERIC_AUTH_PROVIDER']);
    unifiedAdminFailure(fn () => $authentication->handle(['action' => 'select']), 'Session-or-key mode accepted no credential.', 'AUTHENTICATION_REQUIRED');
    $service->saveAuthentication('session');
    unifiedAdminFailure(fn () => $authentication->handle(['action' => 'select']), 'Session mode accepted no session.', 'AUTHENTICATION_REQUIRED');

    putenv('GENERIC_ADMIN_ENABLED=1');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    (new LocalAdminMiddleware())->handle(['action' => 'admin.status']);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    unifiedAdminFailure(
        fn () => (new LocalAdminMiddleware())->handle(['action' => 'admin.status']),
        'Remote admin request was accepted.',
        'NOT_FOUND'
    );
    putenv('GENERIC_ADMIN_ENABLED=0');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    unifiedAdminFailure(
        fn () => (new LocalAdminMiddleware())->handle(['action' => 'admin.status']),
        'Disabled admin API was accepted.',
        'NOT_FOUND'
    );

    unifiedAdminFailure(
        fn () => (new AuthenticationMiddleware(true, [], $session, new AuthRepository($authPath)))->handle(['action' => 'admin.status']),
        'Unauthenticated admin request was accepted.',
        'AUTHENTICATION_REQUIRED'
    );

    $status = $service->status();
    $encodedStatus = json_encode($status, JSON_THROW_ON_ERROR);
    unifiedAdminAssert(!str_contains($encodedStatus, 'second-secret'), 'Status response exposed the database password.');
    unifiedAdminAssert(!str_contains($encodedStatus, getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE)), 'Status response exposed the encryption key.');

    $windowsLauncher = (string)file_get_contents(__DIR__ . '/../start-windows.bat');
    $linuxLauncher = (string)file_get_contents(__DIR__ . '/../start-linux.sh');
    foreach ([$windowsLauncher, $linuxLauncher] as $launcher) {
        unifiedAdminAssert(str_contains($launcher, '127.0.0.1'), 'Launcher does not bind to loopback.');
        unifiedAdminAssert(!str_contains($launcher, '0.0.0.0'), 'Launcher exposes the admin console to the network.');
        unifiedAdminAssert(str_contains($launcher, 'find-available-port.php'), 'Launcher does not use safe port selection.');
        unifiedAdminAssert(str_contains($launcher, '/admin'), 'Launcher does not display the admin URL.');
    }
    unifiedAdminAssert(str_contains((string)file_get_contents(__DIR__ . '/../admin/api.php'), 'AdminAuthorizationMiddleware'), 'Independent Admin authorization boundary is missing.');
    unifiedAdminAssert(str_contains((string)file_get_contents(__DIR__ . '/../admin/router.php'), "['127.0.0.1', '::1']"), 'Admin router lacks loopback enforcement.');

    echo "Unified admin console tests passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    foreach (glob($sessionPath . '/*') ?: [] as $file) @unlink($file);
    foreach (glob($temporaryDirectory . '/*') ?: [] as $file) {
        if (is_dir($file)) @rmdir($file); else @unlink($file);
    }
    @rmdir($temporaryDirectory);
    $restore = static function (string $name, $value): void {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    };
    $restore('GENERIC_ADMIN_CONFIG_PATH', $oldAdminPath);
    $restore(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, $oldEncryptionKey);
    $restore(ApiKeyAuthenticator::ENVIRONMENT_VARIABLE, $oldApiKey);
    $restore('GENERIC_ADMIN_ENABLED', $oldAdminEnabled);
    $restore('GENERIC_API_ALLOWED_ORIGINS', $oldOriginOverride);
}
