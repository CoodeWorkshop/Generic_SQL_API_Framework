# Authorization, identities, and roles

The framework stores one local identity and one password hash per user. Authorization is split into independent domains on that identity:

- `backendRole`: `read-only`, `data-operator`, `system-administrator`, or `null`.
- `frontendAccess`: whether the identity may use the reporting application.
- `frontendRole`: `application-administrator` or `null`.

Frontend access grants frontend read capability. The current frontend has no CRUD screens, so Application Administrator adds frontend user management but no write capability. It never grants Backend Admin Console, database/configuration, API-key, runtime-control, or backend-role access. Those operations require System Administrator and are enforced by backend middleware.

| Role | Domain | Read | Write | Administration |
| --- | --- | ---: | ---: | --- |
| Read Only | Backend | yes | no | no |
| Data Operator | Backend | yes | yes | no |
| System Administrator | Backend | yes | yes | Backend Admin Console and configuration |
| Application Administrator | Frontend | yes | no* | Frontend users only |

\* Frontend write remains unavailable while the frontend is read-only.

Read includes SELECT, SQL resources, UNION/UNION ALL, routines, metadata, filtering, sorting, pagination, and SQL-resource execution. Write includes INSERT, UPDATE, DELETE, and UPSERT. Resource registries, request validation, prepared parameters, and SQL safety still apply after authorization.

Every protected request resolves to an internal `Principal`. Session users use their current stored domain assignments. Managed API keys contain exactly one backend role, retain hashing/one-time reveal/revocation behavior, and resolve their enabled owner. Legacy API keys and `none` mode map to the configured Read Only policy.

Role or identity changes increment `authVersion`, invalidating existing sessions. The last enabled System Administrator cannot be demoted, disabled, or deleted.

## Migration

Auth schema version 4 preserves IDs, usernames, password hashes, enabled state, and creation dates. Legacy Admin maps to System Administrator plus Application Administrator; Data Editor maps to Data Operator; Viewer and Developer map to Read Only. Existing users previously had access to the frontend, so migration preserves frontend access. This is the non-destructive mapping for the prior model, where `isAdmin` represented both administration surfaces.
