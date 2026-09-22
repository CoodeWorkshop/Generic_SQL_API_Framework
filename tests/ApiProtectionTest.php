<?php

require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Security/PasswordHasher.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../core/QueryEngine.php';

final class NoAuthExecutionEngine extends QueryEngine
{
    public function __construct()
    {
        parent::__construct(null, new Logger(sys_get_temp_dir()), 5, false);
    }

    protected function prepareStatement(string $sql) { return (object)['fetched' => false]; }
    protected function configureStatementTimeout($statement): bool { return true; }
    protected function executeStatement($statement, array $params): bool { return $params === ['active']; }
    protected function fetchRow($statement) { if ($statement->fetched) return false; $statement->fetched = true; return ['ok' => 1]; }
    protected function freeStatement($statement): void {}
}

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
$adminPath = $directory . DIRECTORY_SEPARATOR . 'admin.json';
$sessionName = 'generic_reporting_protection_' . bin2hex(random_bytes(4));
$oldAdminPath = getenv('GENERIC_ADMIN_CONFIG_PATH');
$publicActions = [
    'setup.status',
    'setup.createAdmin',
    'auth.csrf',
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
    (new AdminConfigurationRepository($adminPath))->save(AdminConfigurationRepository::defaults());
    putenv('GENERIC_ADMIN_CONFIG_PATH=' . $adminPath);

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

    $noneConfiguration = AdminConfigurationRepository::defaults();
    $noneConfiguration['authentication']['mode'] = 'none';
    (new AdminConfigurationRepository($adminPath))->save($noneConfiguration);
    $middleware->handle(['action' => 'select']);
    protectionAssert(($_SERVER['GENERIC_AUTH_PROVIDER'] ?? null) === 'none', 'No-auth mode did not reach the query pipeline.');
    $queryResult = (new NoAuthExecutionEngine())->executePrepared('SELECT ?', ['active'], ['queryPhase' => 'data']);
    protectionAssert($queryResult['data'] === [['ok' => 1]], 'A valid query failed after no-auth middleware acceptance.');
    $sessionConfiguration = $noneConfiguration;
    $sessionConfiguration['authentication']['mode'] = 'session';
    (new AdminConfigurationRepository($adminPath))->save($sessionConfiguration);

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

    $authenticatedUser = $authRepository->findUser('Authenticated.User');
    $session->establish(
        $authenticatedUser['username'],
        $authenticatedUser['id'],
        $authenticatedUser['authVersion']
    );
    foreach ($protectedActions as $action) {
        $middleware->handle(['action' => $action]);
    }

    protectionAssert(
        $_SESSION['generic_reporting_auth'] === [
            'authenticated' => true,
            'username' => 'Authenticated.User',
            'userId' => $authenticatedUser['id'],
            'authVersion' => $authenticatedUser['authVersion'],
        ],
        'Protection middleware altered the safe session identity.'
    );
    protectionAssert(
        is_int($_SESSION['generic_reporting_session_meta']['lastActivity'] ?? null),
        'Protection middleware did not maintain the server-side timeout.'
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
    @unlink($authPath . '.lock');
    @unlink($adminPath);
    @unlink($adminPath . '.lock');
    @rmdir($directory);
    $oldAdminPath === false
        ? putenv('GENERIC_ADMIN_CONFIG_PATH')
        : putenv('GENERIC_ADMIN_CONFIG_PATH=' . $oldAdminPath);
}
