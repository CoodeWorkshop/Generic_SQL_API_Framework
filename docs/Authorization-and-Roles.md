# Authorization and roles

Every protected request resolves to one internal `Principal`, whether authentication used a session or `X-API-Key`. Controllers do not implement their own permission rules.

`AuthorizationService` is deny-by-default:

| Permission | Actions |
| --- | --- |
| `data.read` | `select`, `union`, `unionAll` |
| `data.write` | `insert`, `update`, `delete`, `upsert` |
| `metadata.read` | `metadata.*` |
| `sql.execute` | configured `sql` resources |
| `routine.execute` | `procedure`, `function`, `tableFunction` |
| `admin.manage` | Admin and identity-management operations |

SQL and write actions also require the resource in the role's `sqlResources` or `writeResources`; `*` grants every configured resource of that type. Authorization never replaces resource registries, validation, prepared parameters, or SQL safety.

Runtime `authorization.json` defines Viewer, Developer, Data Editor, and Admin as explicit policy data. Role changes increment `authVersion` and invalidate existing sessions. Admin's `admin.manage` bypass applies only to administrative operations; Admin data requests still pass feature gates, scopes, validation, and SQL safety.

Authentication mode `none` resolves a non-admin public principal using only `publicRoles` (Viewer by default). Missing permissions or scopes are denied with `AUTHORIZATION_DENIED` or the generic `RESOURCE_ACCESS_DENIED`.
