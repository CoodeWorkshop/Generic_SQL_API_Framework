# Authentication and user management

Sessions and managed API keys resolve one internal principal. Local users have one identity and credential with separate `backendRole`, `frontendAccess`, and `frontendRole` fields. Authorization or enabled-state changes increment `authVersion`, so stale sessions fail closed on their next request.

The runtime bootstrap creates auth schema version 4 with an empty user list. Migration preserves existing IDs where present, usernames, password hashes, enabled state, and creation dates. Legacy Admin maps to System Administrator plus Application Administrator; Data Editor maps to Data Operator; Viewer and Developer map to Read Only. See [Authorization and roles](Authorization-and-Roles.md).

Initial setup creates one enabled identity with both System Administrator and Application Administrator. Login returns only username and the three public authorization fields; hashes, internal IDs, session identifiers, and authentication versions remain server-side. Sessions retain only stable identity/version data, not roles, so every request resolves current authorization from storage.

## Backend user administration

These actions require `admin.manage` (System Administrator). Mutations also require the session-bound `X-CSRF-Token`.

| Action | Purpose |
| --- | --- |
| `auth.users.list` | List safe identity and domain assignments |
| `auth.users.create` | Create an identity with backend/frontend assignments |
| `auth.users.update` | Change username |
| `auth.users.changePassword` | Change password |
| `auth.users.enable/disable/delete` | Manage identity lifecycle |
| `auth.users.assignAuthorization` | Assign backend role, frontend access, and frontend role |

The last enabled System Administrator cannot be demoted, disabled, or deleted. The current identity cannot delete itself. Enabled identities require at least one access domain; disabled identities may have neither.

## Frontend user administration

`auth.frontendUsers.*` is a separate server-enforced boundary for Application Administrator and System Administrator. It supports list, create, username edit, password change, enable/disable/delete, and Application Administrator assignment. Its request contract has no backend-role field. Frontend administrators cannot mutate the credentials or lifecycle of identities protected by backend access, and cannot grant backend roles or backend permissions.

Passwords are always hashed with `PASSWORD_DEFAULT`. User responses and logs exclude password hashes, API-key secrets, session data, and internal authentication metadata. Existing timeout, cookie, fixation, rate-limit, and CSRF protections remain in force.
