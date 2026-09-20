<?php

require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Repositories/InstallationRepository.php';
require_once __DIR__ . '/../app/Security/PasswordHasher.php';
require_once __DIR__ . '/../app/Services/AuthSessionService.php';
require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';

function authenticationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function authenticationExpectFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return;
    }
    throw new RuntimeException($message);
}

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'generic-reporting-auth-test-' . bin2hex(random_bytes(8));
$authPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'auth.json';
$installationPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'installation.json';
$sessionPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'sessions';

try {
    mkdir($temporaryDirectory, 0700, true);
    mkdir($sessionPath, 0700, true);

    $hasher = new PasswordHasher();
    $hash = $hasher->hash('correct horse battery staple');
    authenticationAssert($hash !== 'correct horse battery staple', 'Password was stored as plaintext.');
    authenticationAssert($hasher->verify('correct horse battery staple', $hash), 'Password verification failed.');
    authenticationAssert(!$hasher->verify('wrong password', $hash), 'Incorrect password was accepted.');

    $authRepository = new AuthRepository($authPath);
    $authConfiguration = [
        'version' => 1,
        'users' => [[
            'username' => 'Administrator',
            'passwordHash' => $hash,
            'enabled' => true,
            'isAdmin' => true,
        ]],
    ];
    $authRepository->save($authConfiguration);
    authenticationAssert($authRepository->load() === $authConfiguration, 'Authentication storage round trip failed.');
    authenticationAssert(
        $authRepository->findEnabledUser('administrator')['username'] === 'Administrator',
        'Case-insensitive enabled-user lookup failed.'
    );
    authenticationAssert(!str_contains((string)file_get_contents($authPath), 'correct horse'), 'Plaintext password reached auth storage.');
    authenticationExpectFailure(
        fn () => $authRepository->save(['version' => 1, 'users' => [[
            'username' => 'invalid', 'passwordHash' => 'plaintext', 'enabled' => true, 'isAdmin' => false,
        ]]]),
        'Authentication storage accepted a plaintext password as a hash.'
    );

    $installationRepository = new InstallationRepository($installationPath);
    $installationConfiguration = [
        'version' => 1,
        'installationId' => bin2hex(random_bytes(32)),
        'initialized' => false,
    ];
    $installationRepository->save($installationConfiguration);
    authenticationAssert(
        $installationRepository->load() === $installationConfiguration,
        'Installation storage round trip failed.'
    );

    $runtimeAuth = (new AuthRepository())->load();
    $runtimeInstallation = (new InstallationRepository())->load();
    if ($runtimeInstallation['initialized'] === false) {
        authenticationAssert($runtimeAuth['users'] === [], 'Uninitialized installation contains credentials.');
    } else {
        authenticationAssert(
            count(array_filter(
                $runtimeAuth['users'],
                static fn (array $user): bool => $user['enabled'] && $user['isAdmin']
            )) >= 1,
            'Initialized installation has no enabled administrator.'
        );
    }
    authenticationAssert(
        preg_match('/^[a-f0-9]{64}$/', $runtimeInstallation['installationId']) === 1,
        'Installation identifier is not a secure 256-bit value.'
    );

    ini_set('session.save_path', $sessionPath);
    $session = new AuthSessionService('generic_reporting_test_' . bin2hex(random_bytes(4)));
    authenticationAssert(!$session->resume(), 'Session resumed without a session cookie.');
    $session->start();
    $anonymousSessionId = session_id();
    $session->establish('Administrator', true);
    authenticationAssert(session_id() !== $anonymousSessionId, 'Session ID was not regenerated after authentication.');
    authenticationAssert($session->isAuthenticated(), 'Established session is not authenticated.');
    authenticationAssert($session->authenticatedUsername() === 'Administrator', 'Session username was not retained.');
    authenticationAssert($session->authenticatedUserIsAdmin(), 'Session administrator flag was not retained.');
    authenticationAssert(!array_key_exists('password', $_SESSION), 'Password was stored in the session.');
    authenticationAssert(!array_key_exists('passwordHash', $_SESSION), 'Password hash was stored in the session.');
    $session->destroy();
    authenticationAssert(session_status() !== PHP_SESSION_ACTIVE, 'Session was not destroyed.');

    $boundary = new AuthenticationMiddleware(false);
    $boundary->handle(['action' => 'select']);
    authenticationAssert(session_status() !== PHP_SESSION_ACTIVE, 'Disabled authentication boundary created a session.');

    echo "Authentication foundation tests passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    foreach (glob($sessionPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($sessionPath);
    foreach (glob($temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($temporaryDirectory);
}
