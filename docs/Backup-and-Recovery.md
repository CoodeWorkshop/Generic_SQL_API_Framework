# Backup and recovery

Phase 4.8 provides a small offline utility for creating, verifying, and staging
application-configuration backups. It is not a scheduler, SQL Server backup
engine, remote storage client, or enterprise backup system.

## Recovery scope

| State | Backup treatment | Security/recovery notes |
|---|---|---|
| Application code, deployment templates, SQL resources, reviewed query files | Preserve a versioned release artifact or protected source repository | Required to restore the exact application version; Git alone is not a disaster-recovery system |
| `config/auth.json` | Included | Contains stable user IDs, password hashes, roles/access state, enabled state, and `authVersion`; treat as credential material |
| `config/installation.json` | Included | Preserves installation identity and initialization state |
| `config/admin.json` | Included | Preserves validated server, CORS, authentication, and runtime/performance settings |
| `config/authorization.json` | Included | Preserves role permissions and resource scopes |
| `config/api-keys.json` | Included | Preserves IDs, fingerprints, owners, roles, status, metadata, and secret hashes—never plaintext API-key secrets |
| `database/config/database.json` | Included only as an AES-256-GCM envelope | The utility refuses plaintext database configuration and never decrypts it into the bundle |
| `GENERIC_SQL_API_ENCRYPTION_KEY` | Never included | Back up separately under a different access boundary; both matching key and envelope are required |
| SQL Server database data | Not handled by PHP | Use SQL Server-native full/differential/transaction-log backup and restore processes |
| PHP sessions | Excluded and disposable | Do not restore authenticated sessions; start recovery with an empty protected session directory |
| Database availability, PID, lock, rate-limit, OPcache, export, upload, and temporary state | Excluded | Recreated or safely reset at startup; database availability should begin disconnected |
| Application/security logs and web-server logs | Excluded from configuration bundle | Preserve separately when investigation, audit, or policy requires it; maintain restrictive permissions |

The application source artifact must include backend-owned `config/sql-resources.php`,
`config/write-resources.php`, and `queries/`. Those executable/reviewed definitions
belong with the exact deployed code version rather than mutable credential state.

## Implemented backup utility

Run the utility while affected services are stopped or configuration changes are
administratively paused. Every path must be absolute and outside the application
root:

```text
php scripts/application-backup.php create <absolute-new-backup-directory>
php scripts/application-backup.php verify <absolute-backup-directory>
php scripts/application-backup.php stage-restore <absolute-backup-directory> <absolute-new-staging-directory>
```

`create` reads each JSON document through its shared I/O lock, validates its
minimum schema, requires an encrypted database envelope and matching environment
key, writes owner-restricted files into a private temporary directory, creates a
manifest, verifies the result, and atomically renames the completed directory.
Failed creation removes the temporary directory.

The manifest contains only format version, UTC creation timestamp, application
name/version, logical filenames, byte sizes, SHA-256 checksums, and a categorical
exclusion list. It contains no configuration values, usernames, hashes, keys,
passwords, connection details, or API-key material.

`verify` detects missing files, invalid JSON/schema, size/checksum changes,
unsupported manifests, plaintext database configuration, and a missing/wrong
encryption key. Integrity-only inspection is available to application code but
the CLI intentionally requires the matching key because its purpose is recovery
readiness, not merely archive inspection.

`stage-restore` verifies the bundle and key, then recreates only the allowlisted
files under a new empty external directory. It never overwrites live state. The
operator must inspect this staging result and perform the production replacement
while services are stopped, using native OS permissions and the existing atomic
configuration mechanisms.

Each file is a valid snapshot, but the six-file set is not a transactional
multi-file snapshot: separate configuration mutations can occur between file
reads. Stop Admin/API/Parser services or otherwise freeze configuration changes
for a fully coordinated recovery point. The regression suite verifies that a
concurrent individual-file update cannot create partial JSON.

## Encryption-key recovery and rotation

Recoverability requires both independent items:

```text
encrypted database.json + matching GENERIC_SQL_API_ENCRYPTION_KEY
                         -> recoverable connection configuration
```

Store the production key in the approved IIS FastCGI or PHP-FPM service
environment outside the repository and every backup bundle. Back it up through a
separate restricted process/account so compromise of one archive does not expose
both ciphertext and key. Loss of the matching key makes the encrypted database
configuration unrecoverable. A key without its matching envelope is also
insufficient.

During key rotation, retain the old key and old encrypted backup until the
configuration has been re-encrypted with the new key, workers have been recycled,
connection tests pass, and a new verified backup plus separately protected new
key exist. Label key versions in the external custody system, never in backup
filenames or manifests with secret values.

## Authentication, API keys, and sessions

Restoring `auth.json` preserves password hashes, stable identity IDs,
authorization, enabled state, and `authVersion`; plaintext passwords are neither
stored nor exported. Restoring `api-keys.json` preserves hashed secret validation,
so a client that still possesses its one-time raw key can continue using it.
The server cannot recover or reveal a lost raw API-key secret. Revoke and issue a
replacement after recovery when the client secret is unavailable or compromise
is suspected.

Never restore PHP session files. Stop workers, discard the old session directory
or start with a new empty owner-restricted directory, then require users to sign
in again. If session files might have escaped custody, increment affected users'
`authVersion` through supported user/authorization changes or reset credentials
after recovery. Do not manufacture sessions or bypass authentication.

## Restore sequence

1. Declare the recovery point and stop or isolate Admin, API, SQL Parser, and web
   workers that could mutate configuration. Preserve damaged state separately
   for investigation without placing it under a web root.
2. Restore the exact tested application release, deployment templates, SQL
   resource definitions, and query files.
3. Verify the application bundle and matching encryption key with the utility.
4. Stage the restore outside the installation and compare its manifest/version
   with the selected release.
5. Install the five runtime configuration files and encrypted database envelope
   with the PHP worker stopped. Do not restore lock/PID/rate/session/availability
   files. Start database availability as disconnected.
6. Provision `GENERIC_SQL_API_ENCRYPTION_KEY` separately to the worker identity.
7. Apply NTFS/POSIX ownership and permissions to configuration, key, session,
   runtime, and log directories. Create a new empty session directory.
8. Validate PHP version/extensions, ODBC driver, hosted PHP configuration, and
   database decryption/connection using the intended service identity.
9. Restore SQL Server data using the independently tested native restore plan
   when database recovery is part of the incident.
10. Start Admin first; verify authentication, authorization, database settings,
    and audit logging. Connect database runtime access, then start API and Parser.
11. Verify HTTPS/routing/security headers and run representative read-only API
    requests before allowing writes or general traffic.
12. Revoke/reissue API keys and invalidate user sessions/credentials according
    to incident scope. Record and retain the recovery evidence under policy.

## Disaster scenarios

- **Database configuration corrupt/deleted:** restore the encrypted envelope and
  matching key, verify it offline, then test connection. Without a backup,
  re-enter and save the configuration through trusted Admin setup.
- **Encryption key unavailable:** recover the exact separately held key. Do not
  generate a replacement for existing ciphertext. If it is permanently lost,
  recreate database configuration and encrypt it under a new key.
- **Authentication configuration corrupt:** restore `auth.json`; otherwise use
  the documented first-time/administrative recovery process under controlled
  access. There is no plaintext-password recovery.
- **Admin/runtime or authorization configuration corrupt:** restore the matching
  verified files. Safe bootstrap defaults can rebuild missing files but cannot
  reconstruct custom policy.
- **API-key configuration corrupt:** restore its hashes/metadata. Lost raw client
  secrets cannot be recovered; create replacements.
- **Application code lost:** redeploy the matching trusted release, then restore
  configuration. Do not combine an untested old configuration with incompatible
  code.
- **Logs lost:** application operation can continue, but investigation/compliance
  evidence may be irrecoverable. Restore archives separately if policy requires.
- **SQL Server database lost:** application configuration cannot restore data;
  use native SQL Server backups and the database team's recovery plan.
- **Entire host lost:** rebuild the OS/web/PHP/ODBC boundary, deploy code, restore
  configuration and key separately, create empty sessions, restore SQL Server as
  required, validate, then return traffic.

## SQL Server responsibility

The framework does not implement SQL Server backups. Database owners must define
recovery-point and recovery-time objectives and use SQL Server-native full
backups, differential backups where useful, and transaction-log backups for
databases using an appropriate recovery model. Backup destinations, encryption,
access, retention, off-host copies, `RESTORE VERIFYONLY` or equivalent validation,
and periodic full restore tests are operational responsibilities. Any schedule
(for example daily full plus more frequent logs) is only an example until it is
matched to measured business requirements and SQL Server configuration.

## Storage, retention, and host-loss protection

Keep bundles in a dedicated directory outside Git repositories and every IIS,
Nginx, frontend, API, Admin, and Parser document root. A host-local copy protects
against some operator errors but not disk failure, ransomware, host compromise,
or accidental host deletion. Maintain an access-separated off-host copy and,
where policy requires, an offline or immutable copy managed by infrastructure.
This repository provides no cloud or immutable-storage integration.

Set separate policy for application configuration, SQL Server data, logs/audit
evidence, and release artifacts. Daily configuration backups and periodic restore
exercises are reasonable examples, not hardcoded requirements. Retention must
follow change frequency, incident needs, legal/privacy requirements, available
storage, and approved destruction policy.

## Windows and Linux operations

On Windows, grant the backup operator and intended recovery identity explicit
NTFS access; keep IIS application-pool identities from browsing archives unless
required. Task Scheduler or approved backup software may invoke the CLI, but no
task is installed by this project. SQL Server service/backup identities need
separate permissions to native database backup destinations.

On Linux, use an owner-restricted directory (`0700` with files `0600` where the
service model permits), a dedicated backup account, and narrowly scoped group
access when necessary. `cron` or a systemd timer may invoke the CLI, but neither
is installed here. PHP-FPM does not need access to long-term archives merely
because it writes live configuration.

## Production validation still required

Repository tests use fake credentials and isolated local directories. Staging
must validate real SQL Server full/log restores, point-in-time recovery where
required, key custody/recovery, IIS/NTFS and Nginx/PHP-FPM/POSIX permissions,
off-host transfer, archive restoration, complete host rebuild, API-key client
behavior, session invalidation, health checks, and representative application
reads/writes. A backup is not considered proven until a restore has succeeded in
an isolated environment.
