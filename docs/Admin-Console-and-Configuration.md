# Admin Console, runtime, and configuration

The local Admin Console is an independent loopback application. The launchers
serve it from `admin/` on the configured Admin port and manage the API as a
separate child process. Stopping or restarting the API therefore does not stop
the console. The React reporting application and standalone SQL Parser are also
separate applications.

Before authentication, the console displays only Initial Setup or Login. After
an enabled administrator signs in, navigation contains:

- **System Health** — Admin/API/database/PHP status, safe API PID/port/start
  information, database connection testing, and Start/Stop/Restart controls.
- **System Info** — read-only OS, architecture, PHP/runtime, versions, ports,
  bind address, ODBC, driver candidates, and installation state.
- **Configuration** — Server, Features, Database, Security, and read-only
  Advanced sections.
- **Users** — the Phase 2 account-management interface.

Every Admin API action remains loopback-only, session authenticated,
administrator authorized, and CSRF protected when it changes state. Hiding the
navigation before login is only a UX measure; backend middleware remains the
security boundary.

## Configuration ownership

`config/admin.json` remains the single generated source of truth. Schema version
2 adds `server` and `features` alongside the existing CORS and authentication
settings. Existing version-1 files are migrated in place without changing CORS
or authentication values. No duplicate configuration store is introduced.

The Server section stores a loopback bind address, API minimum/maximum ports,
and Admin port. API startup probes the range in ascending order, skips the Admin
port, and selects the first bindable port. The selected port, PID, and start time
are written only to ignored runtime state under `runtime/api/`. Missing, stale,
crashed, foreign, and already-running process states are handled by the
fixed-operation manager. Browser requests can request only `start`, `stop`,
`restart`, or `status`; they cannot supply commands, paths, executables, or
arguments.

Server-range changes report that an API restart is required. Feature, database,
CORS, and authentication settings are read per request and apply immediately.
Changing the Admin port takes effect on the next launcher start.

## High-level features

The UI intentionally avoids presenting every internal API action:

| Feature | Backend mapping |
|---|---|
| Read Data | `select`, `sql`, `union`, `unionAll`, `procedure`, `function`, `tableFunction` |
| Write Data | `insert`, `update`, `delete`, `upsert` |
| Pagination | Requests containing `pagination` |
| Sorting | Requests containing `sort`, including nested set-operation queries |
| Metadata | `metadata.*` |

`FeatureAccessMiddleware` enforces these settings before request validation or
execution. Disabled functionality returns HTTP 403 `FEATURE_DISABLED`; frontend
switches are not trusted as authorization.

## Database and security

The Database section reuses the existing validation, request-scoped SQL Server
driver, resolver, and AES-256-GCM envelope. A test opens one temporary
connection and disconnects it in `finally`. Stored plaintext passwords,
ciphertext, encryption keys, session IDs, and hashes are never returned.

The Security section exposes existing authentication-mode and CORS settings.
It does not implement roles, permissions, API-key management, OAuth, or JWT.
Advanced information is read-only and reflects existing timeout/debug/logging
configuration.

## Platform startup

`start-windows.bat` uses `runtime/windows/php/php.exe`.
`start-linux.sh` prefers `runtime/linux/php/php` and falls back to installed PHP
when the bundled Linux executable is absent. Neither launcher asks the operator
to choose an OS. Both bootstrap configuration and encryption, verify the Admin
port, start the managed API, and keep the independent Admin server in the
foreground.

The SQL Parser starts separately from `sqlparser/` using its platform launcher.
It imports only parser classes, never the Admin/API front controllers or
database layer, and never executes SQL.
