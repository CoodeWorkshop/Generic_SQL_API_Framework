# Admin runtime reference

The Admin Console uses dedicated System Administrator actions on its loopback-only `admin/api.php` endpoint:

| Action | Purpose | CSRF |
| --- | --- | ---: |
| `admin.health` | Safe Admin/API/parser/database/PHP status | no |
| `admin.system.info` | Minimal operational system information | no |
| `admin.api.start/stop/restart` | Control the fixed API process | yes |
| `admin.sqlParser.start/stop/restart` | Control the fixed parser process | yes |
| `admin.settings.get` | Read redacted configuration | no |
| `admin.server.save` | Save loopback ports and ranges | yes |
| `admin.database.get/test/save` | Read, test submitted values, or save SQL Server configuration | mutations/tests |
| `admin.database.connect/disconnect/restart` | Control the request-availability gate | yes |
| `admin.cors.save` | Save exact CORS origins | yes |
| `admin.authentication.save` | Save an existing authentication mode | yes |

The general API rejects `admin.*`. Process controls accept fixed operations only and cannot execute user-supplied commands. API and SQL Parser begin stopped and database access begins disconnected; the launchers never auto-start or auto-connect them. If a service cannot start, Admin remains available so a System Administrator can inspect status or change configuration.

System Health is the runtime control plane. API and SQL Parser cards report the actual managed PID, dynamically selected active port, and start time. Stopped, crashed, or stale state contains no PID, port, or start time. The database card reports only safe configured server, explicit port, database name, and availability/health state; its Connect, Disconnect, and Restart actions exist only here, with no Test Saved Configuration operation. Configuration → Database retains only form validation, Test Connection for submitted values, and Save.

There is no global Features configuration. Read, Write, Pagination, Sorting, Metadata, SQL resources, and routines remain supported and are authorized by the backend role model.
