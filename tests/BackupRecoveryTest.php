<?php

require_once __DIR__ . '/../app/Backup/ApplicationBackupManager.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../app/Authorization/RoleModel.php';
require_once __DIR__ . '/../core/JsonFileStore.php';

function backupAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function backupFailure(callable $operation, string $message): Throwable
{
    try { $operation(); }
    catch (Throwable $exception) { return $exception; }
    throw new RuntimeException($message);
}

function backupRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

function backupWriteFixture(string $path, array $value): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) mkdir($directory, 0700, true);
    JsonFileStore::save($path, $value);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-backup-recovery-' . bin2hex(random_bytes(8));
$applicationRoot = $directory . '/application';
$backupParent = $directory . '/protected-backups';
$runtimePath = $applicationRoot . '/config';
$databasePath = $applicationRoot . '/database/config/database.json';
$key = base64_encode(random_bytes(32));
$wrongKey = base64_encode(random_bytes(32));
$oldKey = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);

try {
    mkdir($runtimePath, 0700, true);
    mkdir(dirname($databasePath), 0700, true);
    mkdir($backupParent, 0700, true);
    file_put_contents($applicationRoot . '/config/app.php', "<?php return ['version' => 'test'];\n");
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);

    $passwordHash = password_hash('fake-user-password', PASSWORD_DEFAULT);
    $apiSecretHash = password_hash('fake-api-secret', PASSWORD_DEFAULT);
    backupWriteFixture($runtimePath . '/auth.json', ['version' => 4, 'users' => [[
        'id' => str_repeat('a', 32), 'username' => 'Backup.Admin', 'passwordHash' => $passwordHash,
        'enabled' => true, 'backendRole' => RoleModel::SYSTEM_ADMINISTRATOR,
        'frontendAccess' => true, 'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR,
        'createdAt' => gmdate(DATE_ATOM), 'authVersion' => 7,
    ]]]);
    backupWriteFixture($runtimePath . '/installation.json', [
        'version' => 1, 'installationId' => str_repeat('b', 64), 'initialized' => true,
    ]);
    backupWriteFixture($runtimePath . '/admin.json', AdminConfigurationRepository::defaults());
    backupWriteFixture($runtimePath . '/authorization.json', RuntimeConfiguration::authorizationDefaults());
    backupWriteFixture($runtimePath . '/api-keys.json', ['version' => 2, 'keys' => [[
        'id' => str_repeat('c', 16), 'name' => 'Backup key', 'ownerUserId' => str_repeat('a', 32),
        'roles' => [RoleModel::READ_ONLY], 'secretHash' => $apiSecretHash,
        'fingerprint' => str_repeat('d', 12), 'enabled' => true, 'revokedAt' => null,
        'createdAt' => gmdate(DATE_ATOM), 'lastUsedAt' => null,
    ]]]);
    backupWriteFixture($runtimePath . '/database-state.json', ['version' => 1, 'available' => true, 'updatedAt' => gmdate(DATE_ATOM)]);
    $database = [
        'provider' => 'sqlserver', 'driver' => 'auto', 'server' => 'backup-db.internal',
        'port' => '1433', 'database' => 'BackupTest', 'authentication' => 'sql',
        'username' => 'backup_user', 'password' => 'fake-database-password',
        'options' => ['encrypt' => true, 'trustServerCertificate' => false],
    ];
    backupWriteFixture($databasePath, (new DatabaseCredentialEncryption())->encryptConfiguration($database));

    $sessionDirectory = $applicationRoot . '/sessions';
    mkdir($sessionDirectory, 0700, true);
    file_put_contents($sessionDirectory . '/sess_fake', 'authenticated-session-data');
    $keyPath = $applicationRoot . '/runtime/secrets/database-encryption.key';
    mkdir(dirname($keyPath), 0700, true);
    file_put_contents($keyPath, $key);

    $sources = [
        'config/auth.json' => $runtimePath . '/auth.json',
        'config/installation.json' => $runtimePath . '/installation.json',
        'config/admin.json' => $runtimePath . '/admin.json',
        'config/authorization.json' => $runtimePath . '/authorization.json',
        'config/api-keys.json' => $runtimePath . '/api-keys.json',
        'database/config/database.json' => $databasePath,
    ];
    $manager = new ApplicationBackupManager($applicationRoot, $sources, 'test-version');
    $bundlePath = $backupParent . '/backup-one';
    $manifest = $manager->create($bundlePath);
    backupAssert(count($manifest['files']) === 6, 'Backup manifest omitted required application state.');
    foreach ($manifest['files'] as $file) {
        $backedUpPath = $bundlePath . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
        clearstatcache(true, $backedUpPath);
        backupAssert(
            $file['size'] === filesize($backedUpPath)
                && hash_equals($file['sha256'], hash_file('sha256', $backedUpPath)),
            "Backup manifest integrity metadata does not match {$file['path']}."
        );
    }
    backupAssert($manager->verify($bundlePath)['formatVersion'] === 1, 'Backup verification failed.');

    $manifestContents = (string)file_get_contents($bundlePath . '/manifest.json');
    foreach ([$key, 'fake-user-password', 'fake-api-secret', 'fake-database-password', $passwordHash, $apiSecretHash] as $secret) {
        backupAssert(!str_contains($manifestContents, $secret), 'Backup manifest exposed secret material.');
    }
    $databaseBackup = (string)file_get_contents($bundlePath . '/database/config/database.json');
    backupAssert(str_contains($databaseBackup, '"encrypted": true'), 'Database backup is not encrypted.');
    foreach (['fake-database-password', 'backup-db.internal', 'backup_user'] as $secret) {
        backupAssert(!str_contains($databaseBackup, $secret), 'Encrypted database backup exposed plaintext configuration.');
    }
    $apiBackup = (string)file_get_contents($bundlePath . '/config/api-keys.json');
    backupAssert(!str_contains($apiBackup, 'fake-api-secret'), 'API-key plaintext secret was exported.');
    backupAssert(!is_dir($bundlePath . '/sessions'), 'Session state was included in the backup.');
    backupAssert(!is_file($bundlePath . '/runtime/secrets/database-encryption.key'), 'Encryption key was included in the backup.');
    backupAssert(!is_file($bundlePath . '/config/database-state.json'), 'Disposable database availability state was included.');
    if (PHP_OS_FAMILY !== 'Windows') {
        clearstatcache(true, $bundlePath . '/manifest.json');
        backupAssert((fileperms($bundlePath . '/manifest.json') & 0777) === 0600, 'Backup file permissions are not owner-only.');
        backupAssert((fileperms($bundlePath) & 0777) === 0700, 'Backup directory permissions are not owner-only.');
    }

    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    backupFailure(fn () => $manager->verify($bundlePath, true), 'Backup verified without its encryption key.');
    backupAssert($manager->verify($bundlePath, false)['formatVersion'] === 1, 'Integrity-only verification incorrectly required a key.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $wrongKey);
    backupFailure(fn () => $manager->verify($bundlePath, true), 'Backup verified with the wrong encryption key.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);

    $adminBackupPath = $bundlePath . '/config/admin.json';
    $originalAdminBackup = (string)file_get_contents($adminBackupPath);
    file_put_contents($adminBackupPath, $originalAdminBackup . 'corruption');
    backupFailure(fn () => $manager->verify($bundlePath), 'Corrupted backup was accepted.');
    file_put_contents($adminBackupPath, $originalAdminBackup);
    backupAssert($manager->verify($bundlePath)['formatVersion'] === 1, 'Repaired backup did not verify.');

    $missingPath = $bundlePath . '/config/authorization.json';
    $missingContents = (string)file_get_contents($missingPath);
    unlink($missingPath);
    backupFailure(fn () => $manager->verify($bundlePath), 'Backup with a missing file was accepted.');
    file_put_contents($missingPath, $missingContents);
    chmod($missingPath, 0600);

    $restorePath = $backupParent . '/restore-stage';
    $restore = $manager->stageRestore($bundlePath, $restorePath);
    backupAssert($restore['ready'] === true && $restore['files'] === 6, 'Restore prerequisite staging failed.');
    backupAssert(!is_dir($restorePath . '/sessions'), 'Restore staging revived old authenticated sessions.');
    backupAssert(!is_file($restorePath . '/config/database-state.json'), 'Restore staging restored disposable runtime state.');
    backupAssert($manager->verify($restorePath)['formatVersion'] === 1, 'Staged restore failed integrity validation.');

    $workerCommand = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $workerCommand[] = '-n';
    $workerCommand[] = __DIR__ . '/RuntimeConcurrencyWorker.php';
    array_push($workerCommand, 'admin-update', $runtimePath . '/admin.json', '100');
    $process = proc_open($workerCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    backupAssert(is_resource($process), 'Concurrent configuration writer could not start.');
    fclose($pipes[0]);
    $concurrentBundle = $backupParent . '/backup-concurrent';
    $manager->create($concurrentBundle);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    backupAssert(proc_close($process) === 0 && $stderr === '', 'Concurrent configuration writer failed: ' . $stdout . $stderr);
    backupAssert($manager->verify($concurrentBundle)['formatVersion'] === 1, 'Concurrent backup produced invalid configuration.');

    $brokenSources = $sources;
    $brokenSources['config/auth.json'] = $applicationRoot . '/missing-auth.json';
    $brokenManager = new ApplicationBackupManager($applicationRoot, $brokenSources, 'test-version');
    $failedBundle = $backupParent . '/backup-failed';
    backupFailure(fn () => $brokenManager->create($failedBundle), 'Backup with a missing source succeeded.');
    backupAssert(!file_exists($failedBundle), 'Failed backup left a finalized bundle.');
    backupAssert(glob($backupParent . '/.backup-failed.tmp.*') === [], 'Failed backup left sensitive temporary files.');

    backupFailure(fn () => $manager->create($applicationRoot . '/unsafe-backup'), 'Backup was allowed inside the application root.');

    echo "Backup and recovery tests passed.\n";
} finally {
    $oldKey === false
        ? putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE)
        : putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $oldKey);
    backupRemoveDirectory($directory);
}
