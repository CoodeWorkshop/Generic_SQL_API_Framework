<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Repositories/InstallationRepository.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Security/PasswordHasher.php';

function bootstrapAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function removeBootstrapFixture(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (glob($directory . '/*') ?: [] as $entry) {
        if (is_dir($entry)) removeBootstrapFixture($entry); else @unlink($entry);
    }
    foreach (glob($directory . '/.*') ?: [] as $entry) {
        if (!in_array(basename($entry), ['.', '..'], true) && is_file($entry)) @unlink($entry);
    }
    @rmdir($directory);
}

$root = sys_get_temp_dir() . '/generic-sql-bootstrap-' . bin2hex(random_bytes(8));
$firstDirectory = $root . '/first';
$secondDirectory = $root . '/second';
$legacyDirectory = $root . '/legacy';
$previousDirectory = getenv('GENERIC_RUNTIME_CONFIG_DIR');

try {
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $firstDirectory);
    $result = RuntimeConfiguration::ensure();
    bootstrapAssert(count($result['created']) === 3, 'Fresh bootstrap did not create all runtime files.');
    foreach ([RuntimeConfiguration::AUTH_FILE, RuntimeConfiguration::INSTALLATION_FILE, RuntimeConfiguration::ADMIN_FILE] as $file) {
        bootstrapAssert(is_file($firstDirectory . '/' . $file), "Bootstrap did not create {$file}.");
    }

    $auth = JsonFileStore::load($firstDirectory . '/auth.json');
    $installation = JsonFileStore::load($firstDirectory . '/installation.json');
    $admin = JsonFileStore::load($firstDirectory . '/admin.json');
    bootstrapAssert($auth === ['version' => 2, 'users' => []], 'Auth defaults are unsafe or malformed.');
    bootstrapAssert($installation['initialized'] === false, 'Fresh installation was initialized automatically.');
    bootstrapAssert(preg_match('/^[a-f0-9]{64}$/', $installation['installationId']) === 1, 'Installation ID was not generated securely.');
    bootstrapAssert($admin['authentication']['mode'] === 'session', 'Authentication default was not session mode.');
    bootstrapAssert(
        $admin['version'] === 2
            && $admin['server']['apiPortMinimum'] === 8000
            && $admin['server']['apiPortMaximum'] === 8100
            && $admin['server']['adminPort'] === 8090
            && $admin['server']['bindAddress'] === '127.0.0.1'
            && !in_array(false, $admin['features'], true),
        'Admin runtime defaults are unsafe or malformed.'
    );
    $serializedDefaults = json_encode([$auth, $installation, $admin], JSON_THROW_ON_ERROR);
    foreach (['password', 'passwordHash', 'apiKey', 'encryptionKey'] as $secretName) {
        bootstrapAssert(!str_contains($serializedDefaults, $secretName), "Bootstrap generated {$secretName} material.");
    }

    $admin['authentication']['mode'] = 'none';
    JsonFileStore::save($firstDirectory . '/admin.json', $admin);
    bootstrapAssert(RuntimeConfiguration::ensure()['created'] === [], 'Repeat bootstrap recreated existing configuration.');
    bootstrapAssert(JsonFileStore::load($firstDirectory . '/admin.json')['authentication']['mode'] === 'none', 'Bootstrap overwrote an existing setting.');

    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $secondDirectory);
    RuntimeConfiguration::ensure();
    $secondInstallation = JsonFileStore::load($secondDirectory . '/installation.json');
    bootstrapAssert($secondInstallation['installationId'] !== $installation['installationId'], 'Installation IDs were predictable or reused.');

    mkdir($legacyDirectory, 0700, true);
    $legacyHash = (new PasswordHasher())->hash('legacy-password-123');
    JsonFileStore::save($legacyDirectory . '/auth.json', ['version' => 1, 'users' => [[
        'username' => 'Legacy.Admin',
        'passwordHash' => $legacyHash,
        'enabled' => true,
        'isAdmin' => true,
    ]]]);
    $legacy = (new AuthRepository($legacyDirectory . '/auth.json'))->load();
    bootstrapAssert($legacy['version'] === 2, 'Legacy authentication configuration was not migrated.');
    bootstrapAssert($legacy['users'][0]['passwordHash'] === $legacyHash, 'Migration changed the existing password hash.');
    bootstrapAssert($legacy['users'][0]['username'] === 'Legacy.Admin', 'Migration changed the existing username.');
    bootstrapAssert(preg_match('/^[a-f0-9]{32}$/', $legacy['users'][0]['id']) === 1, 'Migration did not create a stable user ID.');
    bootstrapAssert($legacy['users'][0]['authVersion'] === 1, 'Migration did not initialize session versioning.');

    $gitignore = (string)file_get_contents(__DIR__ . '/../.gitignore');
    foreach (['config/auth.json', 'config/installation.json', 'config/admin.json'] as $ignoredPath) {
        bootstrapAssert(str_contains($gitignore, $ignoredPath), "{$ignoredPath} is not ignored.");
    }
    foreach (['auth', 'installation', 'admin'] as $name) {
        $example = (string)file_get_contents(__DIR__ . "/../config/{$name}.example.json");
        bootstrapAssert(!str_contains($example, 'passwordHash'), "{$name} example contains credential material.");
    }
    foreach (['start-windows.bat', 'start-linux.sh'] as $launcher) {
        bootstrapAssert(
            str_contains((string)file_get_contents(__DIR__ . '/../' . $launcher), 'bootstrap-runtime-configuration.php'),
            "{$launcher} does not initialize runtime configuration."
        );
    }

    echo "Runtime configuration bootstrap tests passed.\n";
} finally {
    $previousDirectory === false
        ? putenv('GENERIC_RUNTIME_CONFIG_DIR')
        : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $previousDirectory);
    removeBootstrapFixture($root);
}
