# Admin Console, runtime, and configuration

The Admin Console is an independent loopback application available only to an enabled System Administrator. Loopback enforcement, session authentication, backend authorization, and CSRF validation remain server-side requirements.

The launchers start the Admin Console only. API and SQL Parser begin stopped, and database runtime access begins disconnected. All three are started or connected manually from System Health. Stopping either process never stops Admin. The parser remains independent of API authentication, sessions, and database connectivity.

## Pages

- **System Health** is the runtime control plane. It shows Admin/API/parser/database/PHP health and contains API, SQL Parser, and database lifecycle controls. Database lifecycle actions exist only here.
- **System Info** returns only application, status, platform, PHP runtime, configuration status, database status, and Admin/API/parser service status.
- **Configuration** contains Server, Database, Security, Runtime & Performance, and Advanced. The obsolete global Features section was removed; authorization now decides who can read or write.
- **Configuration → Database** edits, tests, and saves submitted SQL Server settings. It has no Connect, Disconnect, Restart, or saved-configuration test controls. Test Connection uses the values currently in the form and does not change runtime availability.
- **Users** manages username, password, enabled state, backend role, frontend access, and frontend role without exposing hashes, sessions, or secrets.
- **Roles & Permissions** describes the four fixed roles rather than presenting individual operation toggles.
- **API Keys** manages hashed, one-time-reveal keys with one backend role.

## Configuration and runtime ownership

`config/admin.json` schema version 5 contains server, CORS, authentication, and validated runtime configuration. Versions 1–4 migrate in place; obsolete feature values are dropped because Read, Write, Pagination, Sorting, and Metadata remain framework capabilities governed by authorization.

The Server section owns loopback port ranges. Process state under `runtime/` is operational data, not configuration. System Health obtains each managed process's PID, selected port, and start time from that state. Stopped, crashed, and stale processes report null operational fields rather than configured or historical values. Browser requests may select only fixed lifecycle operations and cannot supply commands, paths, executables, or arguments.

The Database section reuses the validated SQL Server configuration, request-scoped driver, and AES-256-GCM credential envelope. System Health exposes only safe server, explicit port, database name, and connection status; it never invents a database PID or default port. Stored plaintext passwords, ciphertext, encryption keys, session IDs, password hashes, and API-key secrets are never returned.

Runtime & Performance controls query timeout, API/login rate limits, session expiration, JSON body size, and pagination defaults/maximums. These values apply to new requests immediately and do not restart a service. See [Runtime and performance controls](Runtime-and-Performance-Controls.md).

## Platform startup

`start-windows.bat` uses the bundled Windows PHP runtime. `start-linux.sh` prefers the bundled Linux runtime and falls back to installed PHP. Both bootstrap missing runtime configuration, prepare the local encryption key, reset database runtime availability to disconnected, and keep only the Admin server in the foreground. API and SQL Parser remain stopped until manually started from System Health.
