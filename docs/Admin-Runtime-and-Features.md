# Admin runtime and feature reference

The Admin Console uses these dedicated administrator actions on its own
loopback endpoint, `admin/api.php`:

| Action | Purpose | CSRF |
|---|---|---:|
| `admin.health` | Safe Admin/API/database/PHP health | no |
| `admin.system.info` | Read-only platform and runtime information | no |
| `admin.api.start` | Start the fixed API process | yes |
| `admin.api.stop` | Stop the tracked API process | yes |
| `admin.api.restart` | Stop and start the API | yes |
| `admin.settings.get` | Read redacted configuration | no |
| `admin.server.save` | Save loopback ports/range | yes |
| `admin.features.save` | Save high-level feature switches | yes |
| `admin.database.get/test/save` | Existing encrypted database workflow | test/save |
| `admin.cors.save` | Save exact CORS origins | yes |
| `admin.authentication.save` | Save an existing authentication mode | yes |

The general API rejects `admin.*` actions. Administrator process controls are
available only through the independently hosted, loopback-only Admin app.

API runtime state is operational data, not configuration. It is ignored under
`runtime/api/` and contains only PID, selected port, start time, and schema
version. The public API `/health` response contains only status, application
version, selected port, start time, and uptime.

If every configured API port is occupied, startup fails safely with “No
available API port was found in the configured range.” The Admin Console remains
running so the administrator can change the range or stop a conflicting
service.
