# Hosting

## Local development

The bundled launchers use PHP's built-in server only for local development. Production uses IIS with PHP FastCGI on Windows or Nginx with PHP-FPM on Linux; see [Production web-server hosting](Production-Security-and-Deployment.md).

### Windows bundled runtime

The repository includes a PHP runtime at `runtime/windows/php/` and a launcher at `start-windows.bat`. A user of this path does not need to install PHP, XAMPP, or WAMP. The machine still needs a SQL Server ODBC driver because the bundled PHP ODBC extension is only the PHP side of the connection.

From the backend root:

```bat
start-windows.bat
```

The implemented startup sequence is:

```text
locate php.exe and php.ini
  -> create runtime/windows/php/opcache and logs
  -> verify ODBC, OpenSSL, JSON, and session with extension_loaded()
  -> create missing ignored runtime configuration with safe defaults
  -> load/generate the ignored local database-encryption key
  -> reset database runtime availability to disconnected
  -> verify the configured Admin loopback port
  -> php -S 127.0.0.1:<admin-port> -t admin admin/router.php
  -> open http://127.0.0.1:<admin-port>/admin
```

The script explicitly loads `runtime/windows/php/php.ini`, configures OPcache's
file cache, and writes PHP errors to `logs/php_errors.log`. It does not require a
working database before startup: configure and test submitted settings through
Configuration → Database, then connect runtime access from System Health. The launcher generates a local key only when neither an environment
key nor key file exists and no already-encrypted database file depends on a
missing key. It never rewrites database configuration itself. The Windows
launcher restricts its short-lived key-output file to the invoking identity
before capture and deletes it immediately after importing the value into the
process environment. Each built-in server is single-process/single-threaded.
API and SQL Parser remain stopped and
database runtime access remains disconnected until manually controlled from
System Health; all services retain independent lifecycles.

Configuration bootstrap creates missing `config/auth.json`,
`config/installation.json`, and `config/admin.json`; it never overwrites existing
values. The same idempotent bootstrap runs in the Linux launcher and repository
load path, so manual file creation is unnecessary.

The Admin Console is `/admin` on its configured port. System Health reports and
controls the independent API and SQL Parser processes and the database
availability gate. Stopping or restarting either process does not stop Admin
Console or the other managed service.

### Linux local runtime

From the backend root run `./start-linux.sh`. It prefers
`runtime/linux/php/php`, falls back to installed PHP when that bundled binary is
absent, and loads `runtime/linux/php/php.ini`. It performs the same bootstrap,
keeps Admin server in the foreground, leaves API and SQL Parser stopped and the database disconnected, and
attempts to open a browser through `xdg-open` or WSL `cmd.exe`.

## Required deployment configuration

Create `database/config/database.json` as described in [Database Configuration](Database-Configuration.md). SQL Server must be reachable and the PHP process identity or SQL credentials must have the needed permissions. For the recommended complete encrypted envelope (or a legacy encrypted password), expose the matching Base64-encoded 32-byte `GENERIC_SQL_API_ENCRYPTION_KEY` through the host's environment or secret manager to the PHP process; do not place the key in the JSON or launcher. The `logs/` directory must be writable; `Logger` creates it if absent and writes dated `YYYY-MM-DD.log` files containing successful and failed SQL execution details.

## Production hosting

For an ad hoc local installation, the backend can run under another PHP installation with the ODBC extension. Enable the console only on a loopback-bound development server:

```bash
GENERIC_ADMIN_ENABLED=1 php -S 127.0.0.1:8090 -t admin admin/router.php
```

Use IIS/FastCGI on Windows or Nginx/PHP-FPM on Linux for production concurrency. Windows uses `php-cgi.exe`; it does not provide PHP-FPM. Size workers, request queues, memory, and SQL Server capacity from target-host measurements. PHP's built-in server and both launchers are development conveniences, not production process managers.

The built-in server handles each managed API/Parser process serially and must not be used to infer production concurrency. IIS FastCGI and PHP-FPM run independent PHP workers with request-scoped ODBC connections and shared local file-backed configuration, rate-limit, session, and runtime state. Those workers must use one reliable local filesystem; the framework does not provide distributed locks or multi-host state coordination. Same-session PHP requests can serialize on the session-file lock as expected.

IIS `web.config` examples, an Nginx HTTPS server-block example, PHP production/OPcache settings, route boundaries, TLS, security headers, permissions, logging, and deployment steps are documented in [Production web-server hosting](Production-Security-and-Deployment.md).

Before exposing a production deployment, install a hostname-valid trusted certificate, validate the HTTP-to-HTTPS redirect and TLS policy, review the exact HTTPS origins in `config/admin.json` (or the explicit environment override), and configure a dedicated PHP session directory outside every web root with worker-only access and cleanup retention compatible with the absolute session timeout. Protect private keys, database JSON, encryption keys, session files, and logs; use a least-privilege SQL identity; and manage PHP/OpenSSL/ODBC updates. Keep the Admin Console on its loopback-only HTTPS application boundary.

The launchers' automatic `runtime/secrets/database-encryption.key` behavior is a
local-development convenience. Production must inject its independently backed
up key through the IIS FastCGI or PHP-FPM service environment and apply native
NTFS/POSIX permissions. See [Database Configuration](Database-Configuration.md)
for password and encryption-key rotation procedures.

## CI versus runtime

`.github/workflows/backend-tests.yml` uses a hosted PHP runtime to lint code and execute faked database-independent tests. CI deliberately does not start the API, load ODBC, create fake credentials, or run `scripts/check-database.php`. No separate live SQL Server integration workflow currently exists.

## Troubleshooting

- `PHP runtime not found` or `php.ini not found`: restore the corresponding bundled files or use another PHP installation.
- `PHP ODBC extension not available`: check `runtime/windows/php/php.ini` and required runtime DLL dependencies.
- `PHP OpenSSL extension not available`: verify that the bundled `php_openssl.dll` is present and enabled in `runtime/windows/php/php.ini`.
- `database.json not found`: start the local console and save settings on its Database page.
- database encryption key errors: restore the matching local key/environment value; the launcher intentionally refuses to replace a missing key for an already-encrypted file.
- database configuration decryption failure: verify that the encrypted configuration envelope and environment key are the matching pair and have not been altered.
- `No compatible SQL Server ODBC driver`: install a supported driver or configure the exact available driver and verify server/authentication settings.
- no API port available: change the validated range in Configuration → Server, then start or restart the API.
- no SQL Parser port available: change the parser range, then start or restart the parser.
- configured Admin port occupied: stop the conflicting service or update the Admin port and relaunch.
- runtime configuration bootstrap failure: use the reported safe reason code. The launcher pins `GENERIC_RUNTIME_CONFIG_DIR` to its repository `config/` directory so stale inherited overrides cannot redirect startup.
- query failures: inspect `logs/YYYY-MM-DD.log` by request ID, SQLSTATE, `errorCategory`, and `queryPhase`. Parameter values and credential material are intentionally omitted.
