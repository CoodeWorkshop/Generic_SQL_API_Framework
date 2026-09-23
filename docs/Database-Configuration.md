# Database Configuration

System Health manages a runtime availability gate, not a permanent SQL connection. **Connect** temporarily validates encrypted configuration and enables new requests. **Disconnect** denies new database requests. **Restart** disables, retests, and re-enables only on success. Configuration → Database's **Test Connection** tests the currently submitted form values through a temporary request-scoped connection without changing availability. There is no separate saved-configuration test action.

## Configuration file

Runtime database settings come from the ignored local file:

```text
database/config/database.json
```

The runtime accepts either the historical plaintext JSON object or the recommended encrypted envelope. Normal reporting requests only read this file. The local Admin Console's Database page is the preferred local configuration path: it validates/tests settings and always saves a complete encrypted envelope.

Leaving the password field blank while editing retains an existing password;
passwords are never returned to the browser. Saving a new configuration requires
a password for SQL authentication. Windows integrated authentication stores no
required password and is available only when the backend runs on Windows.

## Plaintext fields

The plaintext form is retained for backward compatibility and as the input to the one-time migration:

| Field | Type | Required | Behavior |
|---|---|---:|---|
| `provider` | string | yes | Currently `sqlserver` |
| `driver` | string | no | `auto`, or an exact installed ODBC driver name |
| `server` | string | yes | Host, instance, or server address |
| `port` | number/string | no | Appended as `server,port` when supplied |
| `database` | string | yes | Database name |
| `authentication` | string | no | `sql` (default); `windows` only on Windows |
| `username` | string | SQL auth | Empty string if omitted |
| `password` | string | SQL auth | Empty string if omitted |
| `options.encrypt` | boolean-like | no | false (`Encrypt=no`) |
| `options.trustServerCertificate` | boolean-like | no | false (`TrustServerCertificate=no`) |

See `database/config/database.example.json` for a placeholder-only example. The earlier password-only encrypted object remains readable so existing deployments can migrate, but new deployments should encrypt the complete configuration.

## Complete configuration encryption

The recommended deployed `database.json` contains only this versioned envelope:

```json
{
  "encrypted": true,
  "version": 1,
  "algorithm": "AES-256-GCM",
  "nonce": "<base64 nonce>",
  "ciphertext": "<base64 ciphertext>",
  "tag": "<base64 authentication tag>"
}
```

The ciphertext is the serialized complete configuration. Provider, driver, server/IP, port, database, authentication mode, username, password, connection options, and any other properties do not appear outside the authenticated ciphertext. Every encryption uses a fresh random 12-byte nonce and a 16-byte authentication tag.

The key is a random 32-byte value represented in Base64 and supplied separately through `GENERIC_SQL_API_ENCRYPTION_KEY`. It must never be stored in `database.json`, source control, logs, or command output retained as an ordinary file.

Runtime resolution is centralized:

```text
database.json
  -> parse and detect encrypted envelope
  -> read external key
  -> authenticate and decrypt AES-256-GCM payload
  -> decode the complete configuration
  -> existing driver validation and connection path
```

Authentication, malformed-payload, wrong-key, version, and algorithm failures stop before a connection is attempted. Fixed error messages omit decrypted configuration, connection strings, encrypted internals, and key material.

## Admin-managed encryption and migration

For local development, run `start-windows.bat` or `./start-linux.sh`, open the
Database page, optionally test the plaintext settings, and save. This safely
migrates the current values through the same resolver and encryption component.
No `database.json.backup` is created. The launcher prepares an ignored local key
when necessary; Admin Console validates the submitted values, encrypts the whole
configuration, verifies the encrypted round trip, and atomically saves it. The
removed manual key-generation and migration launchers are not part of normal
setup. Production hosts may provide `GENERIC_SQL_API_ENCRYPTION_KEY` from their
secret manager before starting the application.

## Windows authentication

The decrypted configuration may use `authentication: "windows"` only on
Windows. In that mode the driver adds `Trusted_Connection=yes` and supplies empty
ODBC credentials; the PHP process account must have SQL Server access. Linux
Admin responses expose only SQL authentication, crafted Admin requests selecting
Windows authentication are rejected, and the driver enforces the same boundary.

## ODBC and validation

The host needs PHP's `odbc` extension and a compatible SQL Server ODBC driver.
With `driver: "auto"`, the framework tests its supported driver list newest-first
and keeps the first successful connection. With an explicit driver, only that
driver is attempted. Windows retains the ODBC cursor library used by the bundled
runtime; unixODBC platforms use the SQL Server driver's cursor implementation so
statement execution is not rejected with capability error `IM001`.

Use **Test Connection** in Admin Console for the supported workflow. It loads the
current encrypted configuration, validates platform compatibility, opens one
temporary connection, and closes it in `finally`. `scripts/check-database.php`
remains a developer/deployment diagnostic, not a setup requirement.

## Security and troubleshooting

- Keep `GENERIC_SQL_API_ENCRYPTION_KEY` separate from the encrypted file and restrict access to both.
- Never commit `database.json`, plaintext configuration copies, or encryption keys.
- A missing-key error means the PHP worker does not see `GENERIC_SQL_API_ENCRYPTION_KEY`.
- An invalid-key error means the environment value is not strict Base64 for exactly 32 bytes.
- A decryption failure means authentication failed, the payload is malformed, or the key/payload pair does not match.
- Unsupported version/algorithm errors require migration to version `1` and `AES-256-GCM`.
- OpenSSL must be enabled in CLI and hosted PHP runtimes.

Encryption protects all stored connection settings when the configuration file alone is disclosed. A process or operator able to read both the key and ciphertext can decrypt it, because the application must recover the configuration in memory to connect.

The query timeout is separate application configuration.
`DB_QUERY_TIMEOUT_SECONDS` defaults to 45 seconds and is requested when the ODBC
driver supports `SQL_QUERY_TIMEOUT`; unsupported drivers fall back to the PHP
execution limit. Neither timeout controls login, HTTP proxy, or browser timeouts.
