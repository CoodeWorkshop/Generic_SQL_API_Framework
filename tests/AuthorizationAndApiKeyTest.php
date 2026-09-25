<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Services/ApiKeyService.php';
require_once __DIR__ . '/../app/Services/AuthorizationService.php';
require_once __DIR__ . '/../app/Middleware/AuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/FrontendUserAuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';
require_once __DIR__ . '/../app/Authorization/PrincipalContext.php';
require_once __DIR__ . '/../app/Requests/FrontendUserRequestValidator.php';

function authorizationAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function authorizationFails(callable $operation, string $code): void {
    try { $operation(); }
    catch (ApiRequestException $exception) { authorizationAssert($exception->getErrorCode() === $code, "Expected {$code}, got {$exception->getErrorCode()}."); return; }
    throw new RuntimeException("Expected {$code}.");
}
function authorizationRemove(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) { if ($name === '.' || $name === '..') continue; $path=$directory.DIRECTORY_SEPARATOR.$name; is_dir($path) ? authorizationRemove($path) : @unlink($path); }
    @rmdir($directory);
}
function authorizationPrincipal(?string $backendRole, bool $frontendAccess=false, ?string $frontendRole=null, bool $enabled=true): Principal {
    return new Principal(null, 'test-user', 'test', $backendRole, $frontendAccess, $frontendRole, $enabled);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-sql-authorization-' . bin2hex(random_bytes(8));
$oldRuntime = getenv('GENERIC_RUNTIME_CONFIG_DIR');
try {
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory);
    RuntimeConfiguration::ensure();
    $users = new UserManagementService();
    $admin = $users->createUser('System.Admin', 'system-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $reader = $users->createUser('Reader', 'read-only-password', RoleModel::READ_ONLY, false, null);
    $operator = $users->createUser('Operator', 'data-operator-password', RoleModel::DATA_OPERATOR, false, null);
    $storedSystemAdmin = (new AuthRepository())->findUser('System.Admin');
    $systemPrincipal = new Principal($storedSystemAdmin['id'], $storedSystemAdmin['username'], 'session', $storedSystemAdmin['backendRole'], $storedSystemAdmin['frontendAccess'], $storedSystemAdmin['frontendRole'], true);
    $applicationAdmin = $users->createFrontendUser($systemPrincipal, 'Application.Admin', 'application-admin-password', RoleModel::APPLICATION_ADMINISTRATOR);
    authorizationAssert($admin['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR && $reader['backendRole'] === RoleModel::READ_ONLY, 'Users did not receive the requested authorization domains.');
    authorizationAssert(!array_key_exists('passwordHash', $applicationAdmin) && $applicationAdmin['backendRole'] === null && $applicationAdmin['backendProtected'] === false, 'Frontend user response exposed credential details or malformed role state.');
    $frontendValidator = new FrontendUserRequestValidator();
    authorizationFails(fn () => $frontendValidator->validate([
        'action'=>'auth.frontendUsers.assignRole', 'username'=>'Application.Admin',
        'frontendRole'=>RoleModel::APPLICATION_ADMINISTRATOR, 'backendRole'=>RoleModel::SYSTEM_ADMINISTRATOR,
    ]), 'INVALID_FRONTEND_USER_REQUEST');
    $users->updateFrontendUsername($systemPrincipal, 'Application.Admin', 'Application.Owner');
    $users->changeFrontendUserPassword($systemPrincipal, 'Application.Owner', 'replacement-application-password');
    $users->setFrontendUserEnabled($systemPrincipal, 'Application.Owner', false);
    $users->setFrontendUserEnabled($systemPrincipal, 'Application.Owner', true);
    $users->assignFrontendAccess($systemPrincipal, 'Application.Owner', true, null);
    authorizationAssert((new AuthRepository())->findUser('Application.Owner')['backendRole'] === null, 'Frontend management granted backend access.');
    $storedApplicationAdmin = (new AuthRepository())->findUser('Application.Owner');
    $applicationPrincipal = new Principal($storedApplicationAdmin['id'], $storedApplicationAdmin['username'], 'session', null, true, RoleModel::APPLICATION_ADMINISTRATOR, true);
    foreach ([
        fn () => $users->updateFrontendUsername($applicationPrincipal, 'System.Admin', 'Unsafe.Admin'),
        fn () => $users->changeFrontendUserPassword($applicationPrincipal, 'System.Admin', 'unsafe-password-change'),
        fn () => $users->setFrontendUserEnabled($applicationPrincipal, 'System.Admin', false),
        fn () => $users->deleteFrontendUser($applicationPrincipal, 'System.Admin'),
    ] as $operation) authorizationFails($operation, 'AUTHORIZATION_DENIED');
    $users->deleteFrontendUser($systemPrincipal, 'Application.Owner');
    authorizationAssert((new AuthRepository())->findUser('Application.Owner') === null, 'Frontend-only identity was not deleted.');

    $middleware = new AuthorizationMiddleware();
    PrincipalContext::set(authorizationPrincipal(RoleModel::READ_ONLY));
    $middleware->handle(['action'=>'select']); $middleware->handle(['action'=>'sql','resource'=>'reports/sales']);
    authorizationFails(fn () => $middleware->handle(['action'=>'insert','resource'=>'customers']), 'RESOURCE_ACCESS_DENIED');
    authorizationFails(fn () => $middleware->handle(['action'=>'admin.status']), 'AUTHORIZATION_DENIED');
    PrincipalContext::set(authorizationPrincipal(RoleModel::DATA_OPERATOR));
    $middleware->handle(['action'=>'select']); $middleware->handle(['action'=>'upsert','resource'=>'customers']);
    authorizationFails(fn () => $middleware->handle(['action'=>'admin.status']), 'AUTHORIZATION_DENIED');

    PrincipalContext::set(authorizationPrincipal(RoleModel::SYSTEM_ADMINISTRATOR));
    foreach ([['action'=>'select'],['action'=>'delete','resource'=>'customers'],['action'=>'admin.status']] as $request) $middleware->handle($request);

    PrincipalContext::set(authorizationPrincipal(null, true, RoleModel::APPLICATION_ADMINISTRATOR));
    foreach ([['action'=>'select'],['action'=>'union'],['action'=>'metadata.tables'],['action'=>'sql','resource'=>'reports/sales']] as $request) $middleware->handle($request);
    authorizationFails(fn () => $middleware->handle(['action'=>'insert','resource'=>'customers']), 'RESOURCE_ACCESS_DENIED');
    authorizationFails(fn () => $middleware->handle(['action'=>'admin.status']), 'AUTHORIZATION_DENIED');
    authorizationFails(fn () => $middleware->handle([
        'action'=>'auth.users.assignAuthorization',
        'username'=>'Reader',
        'backendRole'=>RoleModel::SYSTEM_ADMINISTRATOR,
    ]), 'AUTHORIZATION_DENIED');
    (new FrontendUserAuthorizationMiddleware(['auth.frontendUsers.list']))->handle(['action'=>'auth.frontendUsers.list']);
    authorizationFails(fn () => (new AuthorizationService())->authorize(PrincipalContext::current(), 'admin.manage'), 'AUTHORIZATION_DENIED');

    PrincipalContext::set(authorizationPrincipal(null, true));
    $middleware->handle(['action'=>'select']);
    authorizationFails(fn () => (new FrontendUserAuthorizationMiddleware(['auth.frontendUsers.list']))->handle(['action'=>'auth.frontendUsers.list']), 'AUTHORIZATION_DENIED');
    PrincipalContext::set(authorizationPrincipal(RoleModel::READ_ONLY, false, null, false));
    authorizationFails(fn () => $middleware->handle(['action'=>'select']), 'AUTHORIZATION_DENIED');

    $before = (new AuthRepository())->findUser('System.Admin')['authVersion'];
    $users->assignAuthorization('System.Admin', RoleModel::SYSTEM_ADMINISTRATOR, false, null);
    authorizationAssert((new AuthRepository())->findUser('System.Admin')['authVersion'] > $before, 'Authorization change did not invalidate sessions.');
    authorizationFails(fn () => $users->assignAuthorization('System.Admin', RoleModel::READ_ONLY, false, null), 'LAST_ENABLED_ADMIN');
    authorizationFails(fn () => $users->setEnabled('System.Admin', false), 'LAST_ENABLED_ADMIN');
    authorizationFails(fn () => $users->deleteUser('System.Admin', 'Other.Admin'), 'LAST_ENABLED_ADMIN');

    $keys = new ApiKeyService();
    $created = $keys->create('Build integration', 'Operator', [RoleModel::DATA_OPERATOR]);
    authorizationAssert(str_starts_with($created['apiKey'], 'gsk_') && strlen($created['fingerprint']) === 12, 'API key was not generated safely.');
    authorizationAssert(!str_contains(json_encode((new ApiKeyRepository())->load(), JSON_THROW_ON_ERROR), $created['apiKey']), 'Plaintext API key reached storage.');
    authorizationAssert($keys->authenticate($created['apiKey'])['owner']['username'] === 'Operator', 'API key owner was not resolved.');
    authorizationFails(fn () => $keys->create('Invalid', 'Operator', [RoleModel::APPLICATION_ADMINISTRATOR]), 'INVALID_API_KEY_REQUEST');
    authorizationFails(fn () => $keys->create('Multiple', 'Operator', [RoleModel::READ_ONLY, RoleModel::DATA_OPERATOR]), 'INVALID_API_KEY_REQUEST');
    $keys->setEnabled($created['id'], false); authorizationAssert($keys->authenticate($created['apiKey']) === null, 'Disabled API key authenticated.');
    $keys->setEnabled($created['id'], true); $keys->revoke($created['id']); authorizationAssert($keys->authenticate($created['apiKey']) === null, 'Revoked API key authenticated.');

    $adminJavaScript = (string)file_get_contents(__DIR__ . '/../admin/assets/admin.js');
    foreach (["serviceControls('api',health.api)", "serviceControls('sqlParser',health.sqlParser)", 'data-database-runtime', 'auth.users.assignAuthorization', "'system-administrator':'Super Admin'", "'application-administrator':'Admin'"] as $marker) authorizationAssert(str_contains($adminJavaScript, $marker), "Admin UI is missing {$marker}.");
    authorizationAssert(!str_contains($adminJavaScript, 'admin.features.save'), 'Removed global feature controls remain in the Admin Console.');
    foreach (['start-linux.sh','start-windows.bat'] as $launcher) {
        $source=(string)file_get_contents(__DIR__.'/../'.$launcher);
        authorizationAssert(!str_contains($source, 'api-runtime-control.php start') && !str_contains($source, 'sqlparser-runtime-control.php start'), "{$launcher} still auto-starts a managed service.");
    }
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    foreach (['auth.users.assignAuthorization','auth.frontendUsers.create','auth.frontendUsers.assignRole','auth.apiKeys.create','admin.database.connect'] as $action) authorizationFails(fn () => (new CsrfProtectionMiddleware())->handle(['action'=>$action]), 'CSRF_VALIDATION_FAILED');

    echo "Authorization and API key tests passed.\n";
} finally {
    PrincipalContext::clear();
    $oldRuntime === false ? putenv('GENERIC_RUNTIME_CONFIG_DIR') : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldRuntime);
    authorizationRemove($directory);
}
