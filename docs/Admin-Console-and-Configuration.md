# Admin Console, runtime, and configuration

The Admin Console is an independent loopback application available only to an enabled System Administrator. Loopback enforcement, session authentication, backend authorization, and CSRF validation remain server-side requirements.

The launchers start the Admin Console only. API and SQL Parser begin stopped and can be started, stopped, restarted, and inspected from System Health. Stopping either service never stops Admin. The parser remains independent of API authentication, sessions, and database connectivity.

## Pages

- **System Health** shows Admin/API/parser/database/PHP health and contains API and SQL Parser lifecycle controls. It does not contain database actions.
- **System Info** returns only application, status, platform, PHP runtime, configuration status, database status, and Admin/API/parser service status.
- **Configuration** contains Server, Database, Security, and Advanced. The obsolete global Features section was removed; authorization now decides who can read or write.
- **Configuration → Database** contains Test Connection, Connect, Disconnect, and Restart. Tests use a temporary request-scoped connection; the framework does not maintain a permanent SQL connection.
- **Users** manages username, password, enabled state, backend role, frontend access, and frontend role without exposing hashes, sessions, or secrets.
- **Roles & Permissions** describes the four fixed roles rather than presenting individual operation toggles.
- **API Keys** manages hashed, one-time-reveal keys with one backend role.

## Configuration and runtime ownership

`config/admin.json` schema version 4 contains server, CORS, and authentication configuration. Versions 1–3 migrate in place; obsolete feature values are dropped because Read, Write, Pagination, Sorting, and Metadata remain framework capabilities governed by authorization.

The Server section owns loopback port ranges. Process state under `runtime/` is operational data, not configuration. Browser requests may select only fixed lifecycle operations and cannot supply commands, paths, executables, or arguments.

The Database section reuses the validated SQL Server configuration, request-scoped driver, and AES-256-GCM credential envelope. Stored plaintext passwords, ciphertext, encryption keys, session IDs, password hashes, and API-key secrets are never returned.

## Platform startup

`start-windows.bat` uses the bundled Windows PHP runtime. `start-linux.sh` prefers the bundled Linux runtime and falls back to installed PHP. Both bootstrap missing runtime configuration, prepare the local encryption key, and keep only the Admin server in the foreground. API and SQL Parser remain stopped until manually started from System Health.
