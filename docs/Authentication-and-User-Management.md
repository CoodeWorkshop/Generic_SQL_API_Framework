# Authentication and user management

The local Admin Console provides first-run administrator setup, login/logout,
and user management at `/admin/users`. Authentication decisions remain entirely
in the backend; browsers never receive password hashes, session identifiers,
authentication version counters, or internal user IDs.

## Automatic configuration bootstrap

Users do not manually create `config/auth.json`, `config/installation.json`, or
`config/admin.json`. Both launchers run
`scripts/bootstrap-runtime-configuration.php`, and the repositories repeat the
same idempotent check for non-launcher hosting. Missing files are created
atomically with restrictive permissions where supported:

- `auth.json`: version 2 and an empty user list;
- `installation.json`: a random 256-bit installation ID and `initialized:false`;
- `admin.json`: safe local CORS defaults and session authentication.

These runtime files and their locks are ignored by Git. The tracked
`*.example.json` files document shapes only and contain no password, hash, API
key, encryption key, or machine identifier. Bootstrap never overwrites an
existing file and never creates plaintext credential backups.

Existing version-1 authentication files are migrated under the authentication
storage lock. Existing usernames, password hashes, enabled status, and
administrator flags are retained. Each user receives a random internal ID, a
creation timestamp based on the existing file timestamp, and an authentication
version used for session invalidation.

## First administrator and login

On a fresh install, `/admin` displays the existing setup form. The backend
validates username/password confirmation, hashes with `PASSWORD_DEFAULT`, stores
one enabled administrator, and atomically marks installation initialized.
Repeated or concurrent initialization is rejected. An interrupted state where
the initial administrator was stored before the installation flag is safely
reconciled during status loading.

Login checks the same generic error path for unknown, disabled, and incorrect
credentials, preserves existing brute-force protection, regenerates the session
ID, and returns only username and administrator status. Logout destroys session
data, expires the cookie, and invalidates its CSRF token.

## User-management actions

All actions require an enabled administrator session. Mutations also require the
existing session-bound `X-CSRF-Token` header and reject unknown properties.

| Action | Required payload | Behavior |
|---|---|---|
| `auth.users.list` | none | Returns username, enabled/admin status, and creation time |
| `auth.users.create` | `username`, `password`, `passwordConfirmation`; optional `enabled`, `isAdmin` | Creates a uniquely named user with a backend-generated hash |
| `auth.users.update` | `username`, `newUsername` | Renames the user while retaining internal identity |
| `auth.users.changePassword` | `username`, `newPassword`, `passwordConfirmation` | Rehashes the password and invalidates existing sessions |
| `auth.users.enable` | `username` | Enables the account and invalidates older sessions |
| `auth.users.disable` | `username` | Disables login and invalidates existing sessions |
| `auth.users.delete` | `username` | Permanently removes a non-current user |

Usernames are case-insensitively unique. Disabling or deleting the last enabled
administrator is rejected. The current administrator cannot delete their own
account. Creating another administrator remains supported by the existing
minimal `isAdmin` flag; generalized roles and permissions are not implemented.

Session invalidation is enforced on the affected user's next authenticated
request by comparing the session's internal user ID and authentication version
with current storage. Disabled and deleted users similarly fail closed on their
next request. Idle and absolute timeouts, HttpOnly/SameSite cookies, HTTPS Secure
cookies, fixation protection, and login throttling remain unchanged.
