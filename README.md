# Generic SQL API Framework

Generic SQL API Framework is a backend-only PHP API that turns validated JSON
query or CRUD descriptions into SQL Server SQL, executes them through ODBC, and
returns a stable JSON envelope. It provides reusable read/query endpoints and
explicitly configured write resources without a controller per table or report.
Clients never submit raw SQL.

The existing PHP implementation is the source of truth. Microsoft SQL Server through ODBC is the only working provider. Driver stubs for MySQL, PostgreSQL, Oracle, and SQLite are not selectable by `DriverFactory` and are not supported providers.

## Public API

Send JSON to the `api/index.php` entry script, normally with `POST` and
`Content-Type: application/json`. It is `/api/index.php` when the repository root
is served, or `/index.php` when `api/` is the document root used by the bundled
launcher:

```json
{
  "action": "select",
  "source": { "table": "Items", "alias": "I" },
  "fields": [
    "I.ItemCode",
    { "field": "I.Description", "alias": "ItemName" }
  ],
  "filters": [
    { "field": "I.Active", "operator": "=", "value": 1 }
  ],
  "sort": [{ "field": "I.ItemCode", "direction": "ASC" }],
  "pagination": { "page": 1, "pageSize": 50 }
}
```

The public actions are `select`, `sql`, `insert`, `update`, `delete`, `upsert`, `union`, `unionAll`, `procedure`, `function`, `tableFunction`, and the five metadata actions documented in [API.md](docs/API.md). Public property names such as `source`, `fields`, `field`, `resource`, `data`, `filters`, and `pagination` are normalized to a private execution model. Internal names such as `table`, `columns`, `column`, `where`, `top`, `page`, and `pageSize` are not accepted as public JSON.

Application actions enforce the configured `none`, `session`, `api_key`, or
`session+api_key` authentication mode. The loopback Admin Console always uses an
administrator session and provides first-run setup plus complete user management.
See [Authentication and user management](docs/Authentication-and-User-Management.md).
Authorization and managed keys are documented in [Authorization and roles](docs/Authorization-and-Roles.md) and [Managed API keys](docs/API-Keys.md).

`sql` is a controlled report-resource action, not a raw-SQL endpoint. The server
recursively discovers reviewed `.sql` files under `queries/`; for example,
`queries/reports/customer.sql` is `reports/customer`. A simple resource needs no
per-file registry entry. Optional, strictly validated `execution` metadata
declares output aliases, filter mappings, and default sorting when runtime UI
controls need them. All runtime values remain prepared parameters.

Because that SQL is backend-owned and reviewed, SQL Resource Mode supports
complex SQL Server SELECT/CTE queries, including joins/APPLY, subqueries,
windows, JSON/XML expressions, and set operations, without using JSON Query
Mode's client-facing function and expression allowlists. Clients still cannot
submit SQL text, tables, paths, credentials, or connection settings.

Current query support includes SELECT, DISTINCT, SQL Server TOP through `limit`, aliases, CASE and arithmetic expressions, an allow-list of SQL functions, prepared WHERE values, INNER/LEFT/RIGHT equality joins, GROUP BY, aggregate HAVING, multi-field sorting, pagination, eight window functions, subqueries in selected filters, one CTE (including the recursive form), UNION/UNION ALL, routines, and database metadata reads. See the definitive [JSON request reference](docs/JSON-Request-Reference.md) and [capability matrix](docs/Capability-Matrix.md) for exact boundaries.

CRUD uses a separate deny-by-default write-resource registry. It supports
single-object INSERT, targeted UPDATE/DELETE, and SQL Server UPSERT with live
metadata validation, prepared values, affected-row reporting, and optional safe
identity output. A deployment must explicitly configure approved write targets.

## Architecture

The actual HTTP flow is:

```text
Client -> api/index.php -> QueryRequestValidator -> QueryRequestNormalizer
       -> QueryController/QueryRepository, SQLController/SqlRepository,
          or WriteController/WriteRepository
       -> QueryEngine -> Database/ODBC -> SQL Server
```

Results return through the same layers and `Response` creates the public envelope. `QueryRepository` is an execution/orchestration facade; SQL construction remains split across `SelectBuilder`, `WhereBuilder`, `JoinBuilder`, `GroupByBuilder`, `HavingBuilder`, `OrderByBuilder`, `PaginationBuilder`, `WindowFunctionBuilder`, `SqlExpressionBuilder`, `RoutineBuilder`, and `SetOperationBuilder`.

See [Architecture.md](docs/Architecture.md) for responsibilities and request/response flow.
Backend developers adding controlled SQL reports should also read
[SQL resource configuration](docs/SQL-Resource-Configuration.md) and
[SQL resource files](docs/SQL-Resource-Files.md). CRUD deployments should read
[write resource configuration](docs/Write-Resource-Configuration.md).

## Configure SQL Server

For local setup, start the backend and use the loopback-only Admin Console at
`/admin`. Its Database page validates/tests the connection and saves the complete
configuration as an AES-256-GCM envelope without a plaintext backup. CORS,
authentication mode, runtime status, and the non-executing SQL parser are also
available there. See [Local Admin Console and configuration](docs/Admin-Console-and-Configuration.md).

The manual format below remains useful for production provisioning. Local users
should configure the database only through Admin Console.

Create the ignored local file `database/config/database.json`:

```json
{
  "provider": "sqlserver",
  "driver": "auto",
  "server": "localhost\\SQLEXPRESS",
  "database": "ApplicationDb",
  "authentication": "windows",
  "options": {
    "encrypt": false,
    "trustServerCertificate": true
  }
}
```

The example's integrated authentication is Windows-only. On every platform, SQL
authentication uses `"authentication": "sql"` plus `username` and `password`.
Plaintext configuration remains supported for compatibility, but deployments can
seal the complete configuration as one AES-256-GCM authenticated payload. The
Base64-encoded 32-byte key comes from `GENERIC_SQL_API_ENCRYPTION_KEY` and must
remain separate from `database.json`.

Admin Console encrypts the complete configuration without retaining a plaintext
backup. Do not commit the key or configuration. See
[Database Configuration](docs/Database-Configuration.md) for the encrypted
format, platform-aware authentication, failure behavior, and security limits.

## Start the backend

No authentication configuration files need to be created first. The launcher
atomically creates missing `config/auth.json`, `config/installation.json`, and
`config/admin.json` with safe defaults; all three are ignored by Git. Existing
files are preserved and legacy authentication data is migrated in place.

On Windows, run:

```bat
start-windows.bat
```

The repository includes `runtime/windows/php/`, so XAMPP or a separate PHP
installation is not required. The launcher validates PHP and required extensions,
prepares the ignored local encryption key, and starts only Admin Console on its
configured loopback port. Start API and SQL Parser manually from System Health;
either may remain stopped. A preconfigured or reachable database is not required.

On Linux, run:

```bash
./start-linux.sh
```

It mirrors the loopback binding, validation, local key preparation, automatic
port selection, and optional browser launch. It prefers
`runtime/linux/php/php` and falls back to installed PHP when the bundled Linux
binary is absent. The Admin Console remains available while its System Health
screen starts, stops, or restarts the API or SQL Parser independently.

With another PHP installation, after configuring the database:

```bash
php -S 127.0.0.1:8000 -t api
```

The built-in server is suitable for local use, not production. See [Hosting.md](docs/Hosting.md).

## Test without a database

Normal backend tests use fakes for query execution and metadata, so they require no SQL Server, ODBC extension, credentials, running server, or `database/config/database.json`:

```bash
php tests/run.php
```

Syntax-check the maintained backend source with:

```bash
find api app config core database scripts tests -type f -name '*.php' -exec php -l {} \;
```

`.github/workflows/backend-tests.yml` runs both checks on every push and pull request using PHP 8.2. There is no mandatory live-database integration workflow.

## Responses

Successful operations return `success`, `message`, `data`, and `meta`. Metadata includes `page`, `pageSize`, `totalRows`, `rowsReturned`, and `executionTime`; writes additionally include `affectedRows`. There is no query-result column-description metadata. Errors return `success: false`, an `error` object, and an empty `data` array. See [API.md](docs/API.md).

## Project status

- v1.0.0 — Core API and advanced SQL: released
- v1.1.0 — Windows runtime and deployment: released
- v1.2.0 — CRUD operations: current
- New Phase 1 — unified local setup/configuration console: implemented
- New Phase 2 — authentication and administrator user management: implemented
- Phase 2.1 — independent Admin/API runtime and simplified configuration UX: implemented
- Phase 2.2 — cross-platform API/parser lifecycle and setup stabilization: implemented
- Phase 3.1 — fixed authorization roles and separated backend/frontend administration: implemented
- Phase 3.2 — configurable runtime and performance controls: implemented
- Transactions, richer metadata, and additional providers: planned

The roadmap is backend-only. See [Roadmap.md](docs/Roadmap.md) and [CHANGELOG.md](CHANGELOG.md).

## Documentation

Start at the [backend documentation map](docs/README.md).

The Admin-managed, application-independent `sqlparser/` service translates
supported SQL into validated public request JSON. See the
[SQL → API JSON Generator](docs/SQL-Parser-Generator.md).

Getting started:

- [Introduction](docs/Introduction.md)
- [HTTP API and Universal JSON Contract](docs/API.md)
- [All public actions](docs/Action-Reference.md)
- [JSON request reference](docs/JSON-Request-Reference.md)
- [Response reference](docs/Response-Reference.md)
- [Frontend integration](docs/Frontend-Integration.md)

Querying and resources:

- [JSON Query Mode](docs/Query-Mode.md)
- [Query functions](docs/Query-Functions.md)
- [Filtering, sorting, and pagination](docs/Filtering-Sorting-Pagination.md)
- [Set operations](docs/Set-Operations.md)
- [SQL Resource Mode](docs/SQL-Resource-Mode.md)
- [SQL Resource configuration](docs/SQL-Resource-Configuration.md)
- [SQL Resource files](docs/SQL-Resource-Files.md)

Writes, metadata, boundaries, and operations:

- [CRUD / Write API](docs/CRUD.md)
- [Write resource configuration](docs/Write-Resource-Configuration.md)
- [Metadata and routines](docs/Metadata-and-Routines.md)
- [Validation, errors, and security](docs/Validation-and-Errors.md)
- [Capability matrix](docs/Capability-Matrix.md)
- [Current limitations](docs/Limitations.md)
- [Architecture](docs/Architecture.md)
- [Local Admin Console and configuration](docs/Admin-Console-and-Configuration.md)
- [Authentication and user management](docs/Authentication-and-User-Management.md)
- [Authorization and roles](docs/Authorization-and-Roles.md)
- [Managed API keys](docs/API-Keys.md)
- [Database configuration](docs/Database-Configuration.md)
- [Hosting](docs/Hosting.md)
- [Roadmap](docs/Roadmap.md), [contributing](CONTRIBUTING.md), and [changelog](CHANGELOG.md)

Do not commit database credentials, `GENERIC_SQL_API_ENCRYPTION_KEY`, or logs. The project scope is the backend API; dashboards, charts, report widgets, and other frontend features belong to consumers, not this repository's backend roadmap.
