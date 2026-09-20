# Hosting

## Windows bundled runtime

The repository includes a PHP runtime at `runtime/windows/php/` and a launcher at `start-windows.bat`. A user of this path does not need to install PHP, XAMPP, or WAMP. The machine still needs a SQL Server ODBC driver because the bundled PHP ODBC extension is only the PHP side of the connection.

From the backend root:

```bat
start-windows.bat
```

The implemented startup sequence is:

```text
locate php.exe and php.ini
  -> create runtime/windows/php/opcache and logs
  -> verify ODBC, OpenSSL, JSON, and session extensions
  -> create missing ignored runtime configuration with safe defaults
  -> load/generate the ignored local database-encryption key
  -> verify the configured Admin loopback port
  -> start the API on the first free configured API port
  -> php -S 127.0.0.1:<admin-port> -t admin admin/router.php
  -> open http://127.0.0.1:<admin-port>/admin
```

The script explicitly loads `runtime/windows/php/php.ini`, configures OPcache's file cache, and writes PHP errors to `logs/php_errors.log`. It does not require a working database before startup: configure and test it through Configuration → Database. The launcher generates a local key only when neither an environment key nor key file exists and no already-encrypted database file depends on a missing key. It never rewrites database configuration itself. Each built-in server is single-process/single-threaded, while the Admin and API processes retain independent lifecycles.

Configuration bootstrap creates missing `config/auth.json`,
`config/installation.json`, and `config/admin.json`; it never overwrites existing
values. The same idempotent bootstrap runs in the Linux launcher and repository
load path, so manual file creation is unnecessary.

The Admin Console is `/admin` on its configured port. System Health reports and
controls the independent API process. Stopping or restarting that API does not
stop the Admin Console.

## Linux local runtime

From the backend root run `./start-linux.sh`. It prefers
`runtime/linux/php/php`, falls back to installed PHP when that bundled binary is
absent, and loads `runtime/linux/php/php.ini`. It performs the same bootstrap,
starts the managed API, keeps the independent Admin server in the foreground,
and attempts to open a browser through `xdg-open` or WSL `cmd.exe`.

### One-time Windows encryption setup

To encrypt an existing plaintext database configuration, run:

```bat
setup-database-encryption.bat
```

The setup uses the bundled PHP and `php.ini`, checks OpenSSL, verifies that `database.json` is plaintext before generating a Base64-encoded 32-byte key, and persists the key as the Windows User environment variable `GENERIC_SQL_API_ENCRYPTION_KEY`. It then encrypts the complete configuration as one AES-256-GCM payload without retaining a plaintext backup and validates the connection through `scripts/check-database.php`.

Open a new terminal—or restart IIS/FastCGI or the relevant service—after setup so the new process inherits the persisted variable. Run the setup only for a plaintext configuration; do not rerun it against an already encrypted envelope. A legacy password-only encrypted configuration remains readable at runtime but requires its existing key and a manual PHP migration.

This manual utility remains available for production-style environment-key
migration. Normal local launcher startup can prepare an ignored local key, while
the Admin Console performs the actual encrypted configuration save.

## Required deployment configuration

Create `database/config/database.json` as described in [Database Configuration](Database-Configuration.md). SQL Server must be reachable and the PHP process identity or SQL credentials must have the needed permissions. For the recommended complete encrypted envelope (or a legacy encrypted password), expose the matching Base64-encoded 32-byte `GENERIC_SQL_API_ENCRYPTION_KEY` through the host's environment or secret manager to the PHP process; do not place the key in the JSON or launcher. The `logs/` directory must be writable; `Logger` creates it if absent and writes dated `YYYY-MM-DD.log` files containing successful and failed SQL execution details.

## Other PHP environments

The backend can run under another PHP installation with the ODBC extension. To
enable the console manually, do so only on a loopback-bound server:

```bash
GENERIC_ADMIN_ENABLED=1 php -S 127.0.0.1:8090 -t admin admin/router.php
```

Use IIS/FastCGI, Apache with multiple PHP workers, or Nginx with PHP FastCGI for concurrent production requests. Windows uses `php-cgi.exe`; do not assume PHP-FPM is available. Size the worker pool and SQL Server connection capacity together. PHP's built-in server and `start-windows.bat` are development/convenience launchers, not production process managers.

The Windows Nginx/PHP FastCGI template, same-origin API routing, TLS/security headers, sensitive-file rules, LAN/Internet guidance, environment variables, and backup requirements are documented in [Production Security and Deployment](Production-Security-and-Deployment.md).

Before production deployment, configure HTTPS at the web server or reverse proxy, review the exact origins in `config/admin.json` (or the explicit environment override), protect the ignored database JSON, encryption key, and logs, use a least-privilege SQL identity, and manage PHP/OpenSSL/ODBC updates. Do not enable or publish the local Admin Console through a production reverse proxy.

## CI versus runtime

`.github/workflows/backend-tests.yml` uses a hosted PHP runtime to lint code and execute faked database-independent tests. CI deliberately does not start the API, load ODBC, create fake credentials, or run `scripts/check-database.php`. No separate live SQL Server integration workflow currently exists.

## Troubleshooting

- `PHP runtime not found` or `php.ini not found`: restore the corresponding bundled files or use another PHP installation.
- `PHP ODBC extension not available`: check `runtime/windows/php/php.ini` and required runtime DLL dependencies.
- `PHP OpenSSL extension not available`: verify that the bundled `php_openssl.dll` is present and enabled in `runtime/windows/php/php.ini`.
- `database.json not found`: start the local console and save settings on its Database page.
- database encryption key errors: restore the matching local key/environment value; the launcher intentionally refuses to replace a missing key for an already-encrypted file.
- database configuration decryption failure: verify that the encrypted configuration envelope and environment key are the matching pair and have not been altered.
- encryption setup says the configuration is already encrypted: do not rerun it; restore the matching persisted key if it was replaced.
- `No compatible SQL Server ODBC driver`: install a supported driver or configure the exact available driver and verify server/authentication settings.
- no API port available: change the validated range in Configuration → Server, then start or restart the API.
- configured Admin port occupied: stop the conflicting service or update the Admin port and relaunch.
- query failures: inspect `logs/YYYY-MM-DD.log` by request ID and `queryPhase` to distinguish count, data, prepare, execute, and fetch time. Parameter values are intentionally omitted.
