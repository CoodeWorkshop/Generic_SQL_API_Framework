# Production security and deployment

## Architecture

```text
Browser
  -> HTTPS
Nginx
  |-> React static files from Frontend/Generic-Reporting-Framework/dist
  `-> /api or /api/index.php
        -> PHP FastCGI
        -> Backend/api/index.php
        -> private SQL Server network
```

The browser uses one HTTPS origin. Build the frontend with `VITE_API_URL=/api`; do not publish a backend port or SQL Server to users. The PHP built-in server remains development-only.

## Windows layout and FastCGI

One suitable layout is:

```text
C:\nginx\
C:\php\
C:\GenericReporting\
  Frontend\Generic-Reporting-Framework\dist\
  Backend\api\
  Backend\app\
  Backend\config\
  Backend\core\
  Backend\database\
  Backend\logs\
  Backend\storage\
```

Copy `deployment/nginx/generic-reporting.windows.example.conf` into the Nginx configuration and replace every example hostname, certificate path, and application path. Merge `deployment/php-production-security.ini` into the production `php.ini`; do not replace extension/ODBC configuration blindly.

Windows PHP provides `php-cgi.exe`, not PHP-FPM. Run `C:\php\php-cgi.exe -b 127.0.0.1:9000` under an organization-approved Windows service supervisor so it restarts on failure and startup. Bind only to loopback and restrict port 9000 with the firewall. Nginx passes `/api` internally to `Backend/api/index.php`. Do not use `php -S` in production.

Validate after substituting real paths:

```bat
C:\nginx\nginx.exe -t
C:\nginx\nginx.exe -s reload
```

Verify `/`, a refreshed client-side route such as `/settings`, `/api`, and `/api/index.php`. Requests to `/.git/`, `/.env`, `/config/auth.json`, `/config/database.json`, and arbitrary `/api/*` paths must be denied or not found.

## Environment configuration

Frontend variables are public and bundled at build time:

```text
VITE_API_URL=/api
```

Backend process variables are server-side:

```text
GENERIC_APP_ENV=production
GENERIC_SQL_API_ENCRYPTION_KEY=<Base64-encoded 32-byte key>
GENERIC_SQL_API_KEY=<at-least-32-byte-api-key-when-enabled>
GENERIC_SESSION_IDLE_TIMEOUT=1800
GENERIC_SESSION_ABSOLUTE_TIMEOUT=28800
GENERIC_LOGIN_MAX_ATTEMPTS=5
GENERIC_LOGIN_WINDOW_SECONDS=900
GENERIC_LOGIN_LOCKOUT_SECONDS=300
```

Validated CORS origins, credential behavior, and allowed methods are stored in
`config/admin.json`. `GENERIC_API_ALLOWED_ORIGINS` is an explicit comma-separated
origin-list override for deployment automation. Wildcards and arbitrary origin
reflection are not supported by the Admin Console. The shipped development list
allows localhost and 127.0.0.1 on ports 5173, 5314, and 5341 and should be
reduced to the origins actually used by a deployment.

Normal API actions enforce the stored authentication mode: `none`, `session`,
`api_key`, or `session+api_key`. API-key mode reads only the server-side
`GENERIC_SQL_API_KEY`; a missing configured key fails closed. Admin and `auth.*`
actions always retain session authentication. API-key-authenticated write
requests do not use browser-session CSRF because the key itself is the
non-cookie credential. Mode `none` also has no cookie-carried authority, so it
does not require CSRF; session-authenticated writes remain CSRF protected.
Mode `none` changes only client authentication. Validation, feature controls,
resource restrictions, prepared values, database credential resolution, and SQL
execution remain unchanged; a `QUERY_ERROR` in this mode is a downstream query
or database failure rather than an authentication rejection.

The local `/admin` console is not a production administration plane. It requires
`GENERIC_ADMIN_ENABLED=1` and a loopback source at both the router and API
middleware, and the provided launchers bind only to `127.0.0.1`. Leave it
disabled and unmapped in production. Use reviewed configuration deployment and
secret-management procedures instead.

Never place `GENERIC_SQL_API_ENCRYPTION_KEY`, passwords, session identifiers, or server paths in `VITE_*`, React source, Nginx public files, or Git. The PHP service account must inherit the encryption key securely.

## Sessions, CSRF, and login protection

Production cookies are Secure, HttpOnly, SameSite=Lax, session-only, strict-mode cookies. Application defaults enforce a 30-minute idle timeout and an eight-hour absolute timeout; both are server-side and stored in validated runtime configuration. The listed environment variables remain bounded deployment overrides. Login regenerates the session identifier. Logout and expiration destroy the session and its CSRF token.

The frontend obtains a random session-bound token through `auth.csrf` and keeps it only in memory. Successful login rotates that token after the session identifier changes and returns the replacement in `X-CSRF-Token`; logout destroys it. Login, logout, setup creation, user mutations, and reporting write actions require the header. Missing or invalid tokens return HTTP 403 with `CSRF_VALIDATION_FAILED`.

Login failures are tracked server-side by source IP plus normalized username. The defaults allow five failures in fifteen minutes before a temporary five-minute block and HTTP 429; all three thresholds are validated runtime settings. A successful login clears that key. Client responses remain identical for unknown, disabled, and incorrectly authenticated users. General API traffic is also protected by the configured local file-backed limiter; multi-host deployments require infrastructure-level distributed controls.

## TLS and headers

The Nginx TLS server applies HSTS (without `preload`), CSP, anti-framing, MIME-sniffing, referrer, and permissions policies. HSTS belongs only on the HTTPS server after a valid certificate is installed. The API also emits restrictive defense-in-depth headers. Production PHP disables browser error display and logs errors server-side.

The CSP permits only same-origin scripts/styles/fonts/API connections and local/data images. The Vite production build uses external generated assets and source maps are disabled. Revalidate the CSP if external resources are introduced later.

## Sensitive files and backups

Nginx serves only `Frontend/dist`; the backend is outside its public root and only the fixed API front controller is mapped. Defense-in-depth rules deny backend directories, dotfiles, JSON, lock, SQL, backup, log, INI, and PHP files. Restrict filesystem permissions on generated `auth.json`, `installation.json`, `admin.json`, the encrypted database configuration, logs, session storage, and rate-limit storage to administrators and the PHP service identity.

The repository contains secret-free `config/*.example.json` templates. Actual
`auth.json`, `installation.json`, and `admin.json` files are generated at runtime
and ignored by Git. Secure backups must include these runtime files and the
encrypted database configuration. Back up `GENERIC_SQL_API_ENCRYPTION_KEY`
separately: the encrypted database configuration is unrecoverable without it.
Never place backups under an Nginx-served directory.

Deleting `auth.json` while installation remains initialized does not reopen setup. Recovery requires an offline, authenticated administrative restore; there is no default password or setup bypass.

## LAN deployment

Use internal DNS and an internal-CA or organization-approved certificate:

```text
Client PCs -> HTTPS internal hostname -> Nginx -> React + PHP -> private SQL Server
```

Every client must trust the issuing CA. A self-signed certificate is acceptable only when its trust anchor is deliberately installed on every managed client; do not disable certificate verification. Permit inbound HTTPS only from intended LAN segments, keep FastCGI loopback-only, and keep SQL Server private.

## Internet deployment

Point DNS to the hardened application server or approved edge, install a publicly trusted certificate, expose only HTTPS (plus HTTP solely for redirect/certificate automation), and restrict administrative access. Keep FastCGI, backend files, and SQL Server off the public Internet. Use a least-privilege database identity and firewall the database connection to the application server.

## Release checklist

1. Build the frontend with `VITE_API_URL=/api` and deploy only `dist/`.
2. Scan `dist/` for secret names/values and confirm there are no `.map` files.
3. Set backend environment variables on the PHP service identity.
4. Apply the PHP production settings and start supervised PHP FastCGI on loopback.
5. Substitute real Nginx paths, hostname, and certificates; run `nginx -t`.
6. Test setup/session/login/logout/settings/admin operations and read/write authorization over HTTPS.
7. Confirm security headers and cookie flags in browser developer tools.
8. Confirm sensitive paths are denied and review filesystem permissions, logs, backups, firewall, and private database routing.

Configuration can be statically validated in the repository, but certificates, DNS, firewall rules, Windows service supervision, and live HTTPS require validation on the target server.
