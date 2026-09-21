# Admin Console, runtime, and configuration

The console includes user role assignment, Roles & Permissions policy visibility, and managed API-key lifecycle management. API and SQL Parser controls share a responsive layout with loading and disabled states. Database Connect, Disconnect, Restart, and Test Connection operate on the request availability gate and temporary connection tests; they never keep a permanent SQL connection.

The local Admin Console is an independent loopback application. The launchers
serve it from `admin/` on the configured Admin port and manage the API and SQL
Parser as separate child processes. Stopping or restarting either service does
not stop the console. The parser remains application-independent: lifecycle
control does not give it an Admin, API, session, or database dependency.

Before authentication, the console displays only Initial Setup or Login. After
an enabled administrator signs in, navigation contains:

- **System Health** — Admin/API/parser/database/PHP status, safe PID/port/start
  information, current-database testing, and separate Start/Stop/Restart controls.
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
3 adds parser ports to the version-2 server/features model. Existing version-1
and version-2 files are migrated in place without changing CORS, authentication,
or feature values. No duplicate configuration store is introduced.

The Server section stores a loopback bind address, separate API and parser port
ranges, and the Admin port. Each service probes its range in ascending order,
skips the Admin port, and selects the first bindable port. Selected ports, PIDs,
start times, and service identities are written only to ignored runtime state.
Missing, stale, crashed, foreign, and already-running states are handled by the
fixed-operation manager. Browser requests can request only `start`, `stop`,
`restart`, or `status`; they cannot supply commands, paths, executables, or
arguments.

Server-range changes report the affected API/parser restarts. Feature, database,
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

SQL authentication is available on every supported platform. Windows integrated
authentication is offered and accepted only on Windows and is rejected by both
Admin validation and the driver on Linux.

The Security section exposes existing authentication-mode and CORS settings.
It does not implement roles, permissions, API-key management, OAuth, or JWT.
Advanced information is read-only and reflects existing debug/logging
configuration. Driver-dependent timeout controls are not advertised as an
enforced Admin option.

## Platform startup

`start-windows.bat` uses `runtime/windows/php/php.exe`.
`start-linux.sh` prefers `runtime/linux/php/php` and falls back to installed PHP
when the bundled Linux executable is absent. Neither launcher asks the operator
to choose an OS. Both pin startup to the repository configuration directory,
bootstrap configuration and encryption, verify the Admin port, start the managed
API and SQL Parser, and keep the independent Admin server in the foreground.
The parser imports only parser classes, never the Admin/API front controllers or
database layer, and never executes SQL.
