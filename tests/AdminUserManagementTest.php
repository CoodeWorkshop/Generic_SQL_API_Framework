<?php

require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/AdminAuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Requests/UserManagementRequestValidator.php';

function userManagementAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function userManagementFailure(callable $operation, string $code, int $status): ApiRequestException
{
    try {
        $operation();
    } catch (ApiRequestException $exception) {
        userManagementAssert($exception->getErrorCode() === $code, "Unexpected error code for {$code}.");
        userManagementAssert($exception->getStatusCode() === $status, "Unexpected status for {$code}.");
        return $exception;
    }
    throw new RuntimeException("Expected failure {$code}.");
}

function destroyUserManagementSession(string $sessionName): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    unset($_COOKIE[$sessionName]);
}

function removeUserManagementFixture(string $directory): void
{
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($directory);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-reporting-users-test-' . bin2hex(random_bytes(8));
$mainDirectory = $root . DIRECTORY_SEPARATOR . 'main';
$concurrentDirectory = $root . DIRECTORY_SEPARATOR . 'concurrent';
$sessionPath = $root . DIRECTORY_SEPARATOR . 'sessions';
$sessionName = 'generic_reporting_users_' . bin2hex(random_bytes(4));
$actions = [
    'auth.users.list', 'auth.users.create', 'auth.users.enable',
    'auth.users.disable', 'auth.users.delete', 'auth.users.changePassword',
];
$publicActions = ['setup.status', 'setup.createAdmin', 'auth.login', 'auth.session', 'auth.logout'];

try {
    mkdir($root, 0700, true);
    mkdir($mainDirectory, 0700, true);
    mkdir($concurrentDirectory, 0700, true);
    mkdir($sessionPath, 0700, true);
    ini_set('session.save_path', $sessionPath);

    $hasher = new PasswordHasher();
    $authPath = $mainDirectory . DIRECTORY_SEPARATOR . 'auth.json';
    $repository = new AuthRepository($authPath);
    $repository->save(['version' => 1, 'users' => [
        [
            'username' => 'Admin',
            'passwordHash' => $hasher->hash('admin-password-123'),
            'enabled' => true,
            'isAdmin' => true,
        ],
        [
            'username' => 'Operator',
            'passwordHash' => $hasher->hash('operator-password-123'),
            'enabled' => true,
            'isAdmin' => false,
        ],
    ]]);
    $session = new AuthSessionService($sessionName);
    $authentication = new AuthenticationMiddleware(true, $publicActions, $session, $repository);
    $authorization = new AdminAuthorizationMiddleware($actions, $session);
    $service = new UserManagementService($repository, $hasher);
    $authService = new AuthService($repository, $hasher, $session);

    foreach ($actions as $action) {
        userManagementFailure(fn () => $authentication->handle(['action' => $action]), 'AUTHENTICATION_REQUIRED', 401);
    }

    $session->establish('Operator', false);
    foreach ($actions as $action) {
        $request = ['action' => $action, 'authenticated' => true, 'isAdmin' => true];
        $authentication->handle($request);
        userManagementFailure(fn () => $authorization->handle($request), 'ADMIN_REQUIRED', 403);
    }
    destroyUserManagementSession($sessionName);

    $session->establish('Admin', true);
    foreach ($actions as $action) {
        $authentication->handle(['action' => $action]);
        $authorization->handle(['action' => $action]);
    }

    $listed = $service->listUsers();
    userManagementAssert(count($listed) === 2, 'Admin could not list users.');
    userManagementAssert(
        !str_contains(json_encode($listed, JSON_THROW_ON_ERROR), 'password'),
        'User list exposed password material.'
    );

    $created = $service->createUser('New.User', 'new-user-password-123', false);
    userManagementAssert($created === [
        'username' => 'New.User', 'enabled' => true, 'isAdmin' => false,
    ], 'Created user response was unsafe or malformed.');
    userManagementFailure(
        fn () => $service->createUser('new.user', 'another-password-123', false),
        'USER_ALREADY_EXISTS',
        409
    );
    $stored = $repository->findUser('New.User');
    userManagementAssert(!array_key_exists('password', $stored), 'Plaintext password was stored.');
    userManagementAssert(
        $stored['passwordHash'] !== 'new-user-password-123'
            && $hasher->verify('new-user-password-123', $stored['passwordHash']),
        'Created password was not safely hashed.'
    );

    destroyUserManagementSession($sessionName);
    $authService->login('New.User', 'new-user-password-123');
    destroyUserManagementSession($sessionName);
    $service->setEnabled('New.User', false);
    userManagementFailure(fn () => $authService->login('New.User', 'new-user-password-123'), 'INVALID_CREDENTIALS', 401);
    $service->setEnabled('New.User', true);
    $authService->login('New.User', 'new-user-password-123');
    destroyUserManagementSession($sessionName);

    $service->changePassword('New.User', 'changed-password-123');
    userManagementFailure(fn () => $authService->login('New.User', 'new-user-password-123'), 'INVALID_CREDENTIALS', 401);
    $authService->login('New.User', 'changed-password-123');
    destroyUserManagementSession($sessionName);

    $service->deleteUser('New.User', 'Admin');
    userManagementFailure(fn () => $authService->login('New.User', 'changed-password-123'), 'INVALID_CREDENTIALS', 401);
    userManagementFailure(fn () => $service->setEnabled('Missing', true), 'USER_NOT_FOUND', 404);

    userManagementFailure(fn () => $service->deleteUser('Admin', 'admin'), 'CANNOT_DELETE_CURRENT_USER', 409);
    userManagementFailure(fn () => $service->setEnabled('Admin', false), 'LAST_ENABLED_ADMIN', 409);
    userManagementFailure(fn () => $service->deleteUser('Admin', 'Other.Admin'), 'LAST_ENABLED_ADMIN', 409);

    $service->createUser('Second.Admin', 'second-admin-password', true);
    $session->establish('Admin', true);
    $service->setEnabled('Admin', false);
    userManagementFailure(fn () => $authentication->handle(['action' => 'select']), 'AUTHENTICATION_REQUIRED', 401);
    userManagementAssert(session_status() !== PHP_SESSION_ACTIVE, 'Disabled account session was not invalidated.');
    $service->setEnabled('Admin', true);

    $service->createUser('Temporary.User', 'temporary-password', false);
    $authService->login('Temporary.User', 'temporary-password');
    $service->deleteUser('Temporary.User', 'Admin');
    userManagementFailure(fn () => $authentication->handle(['action' => 'select']), 'AUTHENTICATION_REQUIRED', 401);
    userManagementAssert(session_status() !== PHP_SESSION_ACTIVE, 'Deleted account session was not invalidated.');

    $validator = new UserManagementRequestValidator();
    $validatedCreate = $validator->validate([
        'action' => 'auth.users.create',
        'username' => 'Default.Role',
        'password' => 'valid-password-123',
    ]);
    userManagementAssert($validatedCreate['isAdmin'] === false, 'Create-user admin flag did not default safely.');
    userManagementFailure(fn () => $validator->validate([
        'action' => 'auth.users.create',
        'username' => 'Unsafe.User',
        'password' => 'valid-password-123',
        'passwordHash' => 'client-hash',
    ]), 'INVALID_USER_REQUEST', 400);
    userManagementFailure(fn () => $validator->validate([
        'action' => 'auth.users.changePassword',
        'username' => 'Operator',
        'newPassword' => 'short',
    ]), 'INVALID_USER_REQUEST', 400);

    $concurrentPath = $concurrentDirectory . DIRECTORY_SEPARATOR . 'auth.json';
    $concurrentRepository = new AuthRepository($concurrentPath);
    $concurrentRepository->save(['version' => 1, 'users' => [[
        'username' => 'Admin',
        'passwordHash' => $hasher->hash('admin-password-123'),
        'enabled' => true,
        'isAdmin' => true,
    ]]]);
    $workers = [];
    foreach (['race-password-123', 'race-password-456'] as $password) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/UserCreationConcurrentWorker.php', $concurrentPath, 'Race.User', $password],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        userManagementAssert(is_resource($process), 'Unable to start concurrent user worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        userManagementAssert(stream_get_contents($pipes[1]) === '', 'Concurrent worker exposed output.');
        userManagementAssert(stream_get_contents($pipes[2]) === '', 'Concurrent worker exposed an error.');
        fclose($pipes[1]);
        fclose($pipes[2]);
        $statuses[] = proc_close($process);
    }
    sort($statuses);
    userManagementAssert($statuses === [0, 2], 'Concurrent duplicate creation was not serialized.');
    $raceUsers = array_values(array_filter(
        $concurrentRepository->load()['users'],
        fn (array $user): bool => strcasecmp($user['username'], 'Race.User') === 0
    ));
    userManagementAssert(count($raceUsers) === 1, 'Concurrent creation stored duplicate usernames.');

    echo "Admin user management tests passed.\n";
} finally {
    destroyUserManagementSession($sessionName);
    foreach (glob($sessionPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath);
    removeUserManagementFixture($mainDirectory);
    removeUserManagementFixture($concurrentDirectory);
    @rmdir($root);
}
