<?php

require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Security/PasswordHasher.php';

function protectionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function protectionRequired(AuthenticationMiddleware $middleware, array $request): ApiRequestException
{
    try {
        $middleware->handle($request);
    } catch (ApiRequestException $exception) {
        protectionAssert($exception->getStatusCode() === 401, 'Protected request did not return HTTP 401.');
        protectionAssert(
            $exception->getErrorCode() === 'AUTHENTICATION_REQUIRED',
            'Protected request returned the wrong error code.'
        );
        protectionAssert(
            $exception->getMessage() === 'Authentication required.' && $exception->getDetails() === [],
            'Authentication failure exposed internal details.'
        );
        return $exception;
    }
    throw new RuntimeException('Unauthenticated protected request was allowed.');
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-reporting-protection-test-'
    . bin2hex(random_bytes(8));
$sessionPath = $directory . DIRECTORY_SEPARATOR . 'sessions';
$authPath = $directory . DIRECTORY_SEPARATOR . 'auth.json';
$sessionName = 'generic_reporting_protection_' . bin2hex(random_bytes(4));
$publicActions = [
    'setup.status',
    'setup.createAdmin',
    'auth.login',
    'auth.session',
    'auth.logout',
];
$protectedActions = [
    'select',
    'sql',
    'union',
    'unionAll',
    'procedure',
    'function',
    'tableFunction',
    'insert',
    'update',
    'delete',
    'upsert',
    'metadata.tables',
    'metadata.columns',
    'metadata.views',
    'metadata.procedures',
    'metadata.schema',
];

try {
    mkdir($directory, 0700, true);
    mkdir($sessionPath, 0700, true);
    ini_set('session.save_path', $sessionPath);

    $session = new AuthSessionService($sessionName);
    $authRepository = new AuthRepository($authPath);
    $authRepository->save(['version' => 1, 'users' => [[
        'username' => 'Authenticated.User',
        'passwordHash' => (new PasswordHasher())->hash('authenticated-password'),
        'enabled' => true,
        'isAdmin' => false,
    ]]]);
    $middleware = new AuthenticationMiddleware(true, $publicActions, $session, $authRepository);

    foreach ($publicActions as $action) {
        $middleware->handle(['action' => $action]);
    }

    foreach ($protectedActions as $action) {
        protectionRequired($middleware, ['action' => $action]);
    }

    protectionRequired($middleware, [
        'action' => 'select',
        'authenticated' => true,
        'isAdmin' => true,
    ]);
    protectionRequired($middleware, ['action' => 'unknown.application.action']);

    $_COOKIE[$sessionName] = 'invalid-session-cookie';
    protectionRequired($middleware, ['action' => 'sql']);
    unset($_COOKIE[$sessionName]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    $session->establish('Authenticated.User', false);
    foreach ($protectedActions as $action) {
        $middleware->handle(['action' => $action]);
    }

    protectionAssert(
        $_SESSION === ['generic_reporting_auth' => [
            'authenticated' => true,
            'username' => 'Authenticated.User',
            'isAdmin' => false,
        ]],
        'Protection middleware altered or expanded the safe session identity.'
    );

    echo "API protection tests passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    unset($_COOKIE[$sessionName]);
    foreach (glob($sessionPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($sessionPath);
    @unlink($authPath);
    @rmdir($directory);
}
