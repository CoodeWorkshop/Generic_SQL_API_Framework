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
| `admin.database.get/test/testCurrent/save` | Configure or temporarily test SQL Server | mutations/tests |
| `admin.database.connect/disconnect/restart` | Control the request-availability gate | yes |
| `admin.cors.save` | Save exact CORS origins | yes |
| `admin.authentication.save` | Save an existing authentication mode | yes |

The general API rejects `admin.*`. Process controls accept fixed operations only and cannot execute user-supplied commands. If a service cannot start, Admin remains available so a System Administrator can inspect status or change configuration.

There is no global Features configuration. Read, Write, Pagination, Sorting, Metadata, SQL resources, and routines remain supported and are authorized by the backend role model.
