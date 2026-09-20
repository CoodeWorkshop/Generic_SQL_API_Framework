# Local Admin Console and configuration

The backend includes one local setup and administration console at `/admin`. It
runs in the same PHP process as the API and is intentionally separate from the
React reporting application.

Start it from the backend root:

```bat
start-windows.bat
```

or:

```bash
./start-linux.sh
```

The launcher validates PHP and required extensions, prepares an ignored local
AES-256-GCM key, selects the first available port from 8000–8100, binds the
server to `127.0.0.1`, and opens `http://127.0.0.1:<port>/admin` when the platform
supports it. The API is already running in that same process at `/index.php`.
Press Ctrl+C in the launcher terminal to stop both the API and console.

The console route and every `admin.*` API action independently require a
loopback client. The launcher also sets the process-local
`GENERIC_ADMIN_ENABLED=1` switch. Admin API actions require an authenticated,
enabled administrator session; state-changing actions use the existing CSRF
boundary. Requests from non-loopback addresses and disabled consoles receive a
not-found response.

## Available pages

- **Overview** shows redacted readiness for database, encryption, CORS,
  authentication, API, parser, PHP, and ODBC.
- **Database** validates, tests, and saves the existing SQL Server configuration
  model. A blank password retains the stored password. Save always writes a
  complete AES-256-GCM envelope and creates no plaintext backup.
- **CORS** stores exact HTTP/HTTPS origins, credential behavior, and the supported
  POST/OPTIONS methods in `config/admin.json`. Wildcards, paths, credentials in
  URLs, duplicates, and unsupported methods are rejected.
- **Authentication** selects `none`, `session`, `api_key`, or
  `session+api_key` for normal API actions. Admin and authentication endpoints
  continue to require sessions. API key values are accepted only from the
  server-side `GENERIC_SQL_API_KEY` environment variable (minimum 32 bytes); the
  console does not create, reveal, or store keys.
- **SQL parser** calls the existing lexer/parser/capability/mapper/generator
  pipeline. It never opens a database connection or executes pasted SQL.
- **Security/System** show redacted runtime information only.

If installation is not initialized, `/admin` first uses the existing one-time
administrator setup. After initialization it uses the existing login/session,
password hashing, authorization, rate-limiting, and CSRF implementation. User
management, roles, permissions, API-key management, production HTTPS, and
production process control are outside this phase.

## Configuration ownership

`config/admin.json` is the fixed, validated store for CORS and API authentication
mode. Database configuration remains at the fixed ignored path
`database/config/database.json`. The UI calls narrowly scoped services; it
cannot choose file paths, write PHP, or run shell commands.

For local launchers, the database encryption key is generated once at
`runtime/secrets/database-encryption.key`, which is ignored by Git and restricted
where the platform supports permissions. If an encrypted database file already
exists but neither its environment key nor local key file is available, startup
fails instead of generating an incompatible key. Production hosts should inject
`GENERIC_SQL_API_ENCRYPTION_KEY` from their secret manager and should not expose
the local console.

`GENERIC_API_ALLOWED_ORIGINS` remains an explicit deployment override for the
stored CORS origin list. When it is unset, the validated backend configuration is
authoritative. No CORS or authentication secrets are placed in frontend build
variables.
