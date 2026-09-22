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

function phase3Assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function phase3Fails(callable $operation, string $code): void {
    try { $operation(); }
    catch (ApiRequestException $exception) { phase3Assert($exception->getErrorCode() === $code, "Expected {$code}, got {$exception->getErrorCode()}."); return; }
    throw new RuntimeException("Expected {$code}.");
}
function phase3Remove(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) { if ($name === '.' || $name === '..') continue; $path=$directory.DIRECTORY_SEPARATOR.$name; is_dir($path) ? phase3Remove($path) : @unlink($path); }
    @rmdir($directory);
}
function phase3Principal(?string $backendRole, bool $frontendAccess=false, ?string $frontendRole=null, bool $enabled=true): Principal {
    return new Principal(null, 'test-user', 'test', $backendRole, $frontendAccess, $frontendRole, $enabled);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-sql-phase31-' . bin2hex(random_bytes(8));
$oldRuntime = getenv('GENERIC_RUNTIME_CONFIG_DIR');
try {
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory);
    RuntimeConfiguration::ensure();
    $users = new UserManagementService();
    $admin = $users->createUser('System.Admin', 'phase-three-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $reader = $users->createUser('Reader', 'phase-three-reader-password', RoleModel::READ_ONLY, false, null);
    $operator = $users->createUser('Operator', 'phase-three-operator-password', RoleModel::DATA_OPERATOR, false, null);
    $applicationAdmin = $users->createFrontendUser('Application.Admin', 'phase-three-application-password', RoleModel::APPLICATION_ADMINISTRATOR);
    phase3Assert($admin['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR && $reader['backendRole'] === RoleModel::READ_ONLY, 'Users did not receive the requested authorization domains.');
    phase3Assert(!array_key_exists('passwordHash', $applicationAdmin) && $applicationAdmin['backendProtected'] === false, 'Frontend user response exposed backend or credential details.');
    $frontendValidator = new FrontendUserRequestValidator();
    phase3Fails(fn () => $frontendValidator->validate([
        'action'=>'auth.frontendUsers.assignRole', 'username'=>'Application.Admin',
        'frontendRole'=>RoleModel::APPLICATION_ADMINISTRATOR, 'backendRole'=>RoleModel::SYSTEM_ADMINISTRATOR,
    ]), 'INVALID_FRONTEND_USER_REQUEST');
    $users->updateUsername('Application.Admin', 'Application.Owner', true);
    $users->changePassword('Application.Owner', 'replacement-application-password', true);
    $users->setEnabled('Application.Owner', false, true);
    $users->setEnabled('Application.Owner', true, true);
    $users->assignFrontendAuthorization('Application.Owner', null);
    phase3Assert((new AuthRepository())->findUser('Application.Owner')['backendRole'] === null, 'Frontend management granted backend access.');
    foreach ([
        fn () => $users->updateUsername('System.Admin', 'Unsafe.Admin', true),
        fn () => $users->changePassword('System.Admin', 'unsafe-password-change', true),
        fn () => $users->setEnabled('System.Admin', false, true),
        fn () => $users->deleteUser('System.Admin', 'Application.Owner', true),
    ] as $operation) phase3Fails($operation, 'BACKEND_IDENTITY_PROTECTED');
    $users->deleteUser('Application.Owner', 'Someone.Else', true);
    phase3Assert((new AuthRepository())->findUser('Application.Owner') === null, 'Frontend-only identity was not deleted.');

    $middleware = new AuthorizationMiddleware();
    PrincipalContext::set(phase3Principal(RoleModel::READ_ONLY));
    $middleware->handle(['action'=>'select']); $middleware->handle(['action'=>'sql','resource'=>'reports/sales']);
    phase3Fails(fn () => $middleware->handle(['action'=>'insert','resource'=>'customers']), 'RESOURCE_ACCESS_DENIED');
    phase3Fails(fn () => $middleware->handle(['action'=>'admin.status']), 'AUTHORIZATION_DENIED');

    PrincipalContext::set(phase3Principal(RoleModel::DATA_OPERATOR));
    $middleware->handle(['action'=>'select']); $middleware->handle(['action'=>'upsert','resource'=>'customers']);
    phase3Fails(fn () => $middleware->handle(['action'=>'admin.status']), 'AUTHORIZATION_DENIED');

    PrincipalContext::set(phase3Principal(RoleModel::SYSTEM_ADMINISTRATOR));
    foreach ([['action'=>'select'],['action'=>'delete','resource'=>'customers'],['action'=>'admin.status']] as $request) $middleware->handle($request);

    PrincipalContext::set(phase3Principal(null, true, RoleModel::APPLICATION_ADMINISTRATOR));
    foreach ([['action'=>'select'],['action'=>'union'],['action'=>'metadata.tables'],['action'=>'sql','resource'=>'reports/sales']] as $request) $middleware->handle($request);
    phase3Fails(fn () => $middleware->handle(['action'=>'insert','resource'=>'customers']), 'RESOURCE_ACCESS_DENIED');
    phase3Fails(fn () => $middleware->handle(['action'=>'admin.status']), 'AUTHORIZATION_DENIED');
    (new FrontendUserAuthorizationMiddleware(['auth.frontendUsers.list']))->handle(['action'=>'auth.frontendUsers.list']);
    phase3Fails(fn () => (new AuthorizationService())->authorize(PrincipalContext::current(), 'admin.manage'), 'AUTHORIZATION_DENIED');

    PrincipalContext::set(phase3Principal(null, true));
    $middleware->handle(['action'=>'select']);
    phase3Fails(fn () => (new FrontendUserAuthorizationMiddleware(['auth.frontendUsers.list']))->handle(['action'=>'auth.frontendUsers.list']), 'AUTHORIZATION_DENIED');
    PrincipalContext::set(phase3Principal(RoleModel::READ_ONLY, false, null, false));
    phase3Fails(fn () => $middleware->handle(['action'=>'select']), 'AUTHORIZATION_DENIED');

    $before = (new AuthRepository())->findUser('System.Admin')['authVersion'];
    $users->assignAuthorization('System.Admin', RoleModel::SYSTEM_ADMINISTRATOR, false, null);
    phase3Assert((new AuthRepository())->findUser('System.Admin')['authVersion'] > $before, 'Authorization change did not invalidate sessions.');
    phase3Fails(fn () => $users->assignAuthorization('System.Admin', RoleModel::READ_ONLY, false, null), 'LAST_ENABLED_ADMIN');
    phase3Fails(fn () => $users->setEnabled('System.Admin', false), 'LAST_ENABLED_ADMIN');
    phase3Fails(fn () => $users->deleteUser('System.Admin', 'Other.Admin'), 'LAST_ENABLED_ADMIN');

    $keys = new ApiKeyService();
    $created = $keys->create('Build integration', 'Operator', [RoleModel::DATA_OPERATOR]);
    phase3Assert(str_starts_with($created['apiKey'], 'gsk_') && strlen($created['fingerprint']) === 12, 'API key was not generated safely.');
    phase3Assert(!str_contains(json_encode((new ApiKeyRepository())->load(), JSON_THROW_ON_ERROR), $created['apiKey']), 'Plaintext API key reached storage.');
    phase3Assert($keys->authenticate($created['apiKey'])['owner']['username'] === 'Operator', 'API key owner was not resolved.');
    phase3Fails(fn () => $keys->create('Invalid', 'Operator', [RoleModel::APPLICATION_ADMINISTRATOR]), 'INVALID_API_KEY_REQUEST');
    phase3Fails(fn () => $keys->create('Multiple', 'Operator', [RoleModel::READ_ONLY, RoleModel::DATA_OPERATOR]), 'INVALID_API_KEY_REQUEST');
    $keys->setEnabled($created['id'], false); phase3Assert($keys->authenticate($created['apiKey']) === null, 'Disabled API key authenticated.');
    $keys->setEnabled($created['id'], true); $keys->revoke($created['id']); phase3Assert($keys->authenticate($created['apiKey']) === null, 'Revoked API key authenticated.');

    $adminJavaScript = (string)file_get_contents(__DIR__ . '/../admin/assets/admin.js');
    foreach (['data-service="api"','data-service="sqlParser"','data-database="connect"','auth.users.assignAuthorization','Application Administrator','System Administrator'] as $marker) phase3Assert(str_contains($adminJavaScript, $marker), "Admin UI is missing {$marker}.");
    phase3Assert(!str_contains($adminJavaScript, 'admin.features.save'), 'Removed global feature controls remain in the Admin Console.');
    foreach (['start-linux.sh','start-windows.bat'] as $launcher) {
        $source=(string)file_get_contents(__DIR__.'/../'.$launcher);
        phase3Assert(!str_contains($source, 'api-runtime-control.php start') && !str_contains($source, 'sqlparser-runtime-control.php start'), "{$launcher} still auto-starts a managed service.");
    }
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    foreach (['auth.users.assignAuthorization','auth.frontendUsers.create','auth.frontendUsers.assignRole','auth.apiKeys.create','admin.database.connect'] as $action) phase3Fails(fn () => (new CsrfProtectionMiddleware())->handle(['action'=>$action]), 'CSRF_VALIDATION_FAILED');

    echo "Authorization and API key tests passed.\n";
} finally {
    PrincipalContext::clear();
    $oldRuntime === false ? putenv('GENERIC_RUNTIME_CONFIG_DIR') : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldRuntime);
    phase3Remove($directory);
}
