# Changelog

## Phase 2.2 — Runtime integration and cross-platform stabilization

- Added independent SQL Parser start/stop/restart/status controls to Admin,
  including validated ports, process identity, health, and crash/stale recovery.
- Made both launchers start API and SQL Parser while Admin remains independent;
  extension checks now use `extension_loaded()` with the selected runtime.
- Removed obsolete manual encryption and parser startup scripts. Admin Console is
  the normal encrypted database configuration workflow.
- Limited Windows integrated database authentication to Windows in the UI,
  backend validator, and driver; SQL authentication remains cross-platform.
- Added safe SQLSTATE/category/phase diagnostics without parameter or credential
  values and confirmed authentication mode `none` reaches normal query execution.
- Pinned launcher bootstrap to the repository config directory and added safe
  reason codes for configuration initialization failures.

## Phase 2.1 — Admin runtime and configuration UX

- Split the loopback Admin Console and API into independently managed processes.
- Added System Health, System Info, unified Configuration, process controls,
  configurable API port selection, runtime detection, and backend-enforced
  high-level feature switches.
- Removed SQL Parser conversion from Admin; the standalone parser remains
  independently hostable and database-free.

All notable backend changes are recorded here. The project follows semantic versioning.

## [Unreleased]

### Added

- New Phase 2 automatic, atomic bootstrap for ignored authentication,
  installation, and admin runtime configuration, with secret-free tracked
  examples and non-destructive version-1 user migration.
- Complete `/admin/users` management for listing, creating, renaming,
  password-changing, enabling, disabling, and deleting accounts.
- Stable internal user identities, creation timestamps, authentication-version
  session invalidation, password confirmation, initial account status, and
  expanded authentication/security regression coverage.

- New Phase 1 unified, backend-hosted `/admin` console for local setup, redacted
  runtime status, encrypted database configuration/testing, exact-origin CORS,
  API authentication-mode selection, and the existing non-executing SQL parser.
- Matching Windows and Linux loopback launchers with runtime checks, local key
  preparation, safe port selection, browser launch where available, and
  independently managed API/Admin processes.
- Local/admin API authorization, CSRF, strict configuration validation, API-key
  authentication-mode enforcement, and database-independent regression tests.

- An independently hosted, non-executing SQL → Universal API JSON developer tool
  with lexical parsing, AST/capability analysis, production request validation,
  browser UI, structured errors, and database-independent tests.
- Recursive SQL Resource auto-discovery with safe path-derived IDs, excluded
  internal directories, collision checks, and unique-basename compatibility.
- Strict frontend execution metadata for output columns, output/source/HAVING
  filter mappings, integer-date conversion, and deterministic default sorting.
- Database-independent discovery, traversal, expression-injection, runtime
  transformation, pagination, compatibility, and collision regression tests.
- Public single-object INSERT, UPDATE, DELETE, and UPSERT actions routed through
  dedicated controller/service/repository and write-builder layers.
- Deny-by-default write-resource registry with per-resource action, schema/table,
  writable/filterable column, UPSERT key, and identity allowlists.
- Live SQL Server write metadata validation, mandatory UPDATE/DELETE targeting,
  prepared DML values, affected-row/identity responses, unique-key verification,
  safe constraint error classification, and database-independent CRUD/security tests.

- Cross-platform AES-256-GCM encryption of the complete database configuration as one versioned authenticated payload, with a Base64-encoded 32-byte key supplied through `GENERIC_SQL_API_ENCRYPTION_KEY`.
- Centralized database configuration resolution that preserves plaintext and legacy password-only compatibility while passing the same decrypted structure into the existing SQL Server ODBC connection path.
- A verified, cross-platform migration utility for converting plaintext `database.json` without retaining a plaintext backup or exposing configuration/key material.
- Windows one-time encryption setup using the bundled PHP/OpenSSL runtime, Windows User environment storage, complete-configuration migration, and connection validation.
- Database-independent encryption coverage for complete-configuration round trips, field confidentiality, random nonces, malformed data, wrong keys, tampering, no-backup migration, backward-compatible resolution, and secret-safe logging.
- Complex server-owned SQL Resource queries, including standard/recursive CTEs, subqueries, complex joins/APPLY, windows, SQL Server functions, JSON/XML expressions, and set operations, without expanding JSON Query Mode allowlists.
- CTE-aware SQL Resource filtering/count/pagination generation and explicit protection against combining request controls with authored OFFSET/FETCH.
- Database-independent SQL Resource capability and security regression coverage.
- Database-independent `tests/run.php` entry point and broader logic coverage for every public filter operator, public joins, representative function families, all window functions, pagination counts, set-operation assembly, and response formatting.
- Ubuntu/PHP 8.2 GitHub Actions workflow for backend syntax checks and all standalone tests without SQL Server, ODBC, credentials, or database configuration.
- Definitive backend capability matrix and detailed public JSON field reference.
- Modularized query construction through `SelectBuilder`, `WhereBuilder`, `JoinBuilder`, `GroupByBuilder`, `HavingBuilder`, `OrderByBuilder`, `PaginationBuilder`, `WindowFunctionBuilder`, `SqlExpressionBuilder`, `RoutineBuilder`, and `SetOperationBuilder`, with `QueryRepository` retained as the facade.
- Public validation/normalization and universal API contract regression coverage.

### Changed

- Reduced SQL Resource configuration to global discovery settings, removed the
  obsolete per-resource registry and runtime-filter marker path, and moved all
  runtime execution metadata to the validated SQL request contract.
- Database configuration migration now verifies encrypted temporary/final files
  without automatically retaining a plaintext backup.
- Restructured backend documentation into a self-contained frontend/API reference
  covering every public action, exact request/response contracts, JSON Query and
  SQL Resource modes, CRUD, metadata/routines, security, capabilities, and
  explicit limitations.
- Reconciled README, architecture, API, examples, database configuration, hosting, roadmap, and contribution guidance with the current implementation.
- Replaced the prior Windows/ODBC environment-validation workflow with logic-focused normal CI. Windows runtime and live database validation remain runtime/manual concerns.

### Fixed

- Added resource-owned logical filter mappings with explicit output, WHERE, and
  HAVING placement, while preserving automatic outer filtering and the legacy
  source marker. Mixed-stage OR and ambiguous set-operation insertion are
  rejected, and runtime values remain prepared parameters.
- Credential configuration, decryption, and key failures now use safe messages and avoid logging credential exception traces or secret values.
- Resolved internal positional ordering to validated logical fields for window functions and legacy pagination, preventing SQL Server from receiving `ROW_NUMBER() OVER (ORDER BY 1)`.
- Added regression coverage that preserves top-level ordering while protecting window contexts.

## [1.1.0] - 2026-08-21

### Added

- Bundled Windows PHP runtime in `runtime/windows/php/`.
- `start-windows.bat` with PHP/php.ini checks, runtime-directory creation, PHP ODBC validation, database validation, API-path checks, and automatic port selection from 8000 through 8100.
- `scripts/check-database.php` startup connection check.
- SQL and Windows authentication configuration, explicit or automatic SQL Server ODBC driver selection, and encryption/trust options.

### Changed

- Windows users can run the backend without installing PHP/XAMPP when using the bundled runtime; a compatible SQL Server ODBC driver and reachable database remain required.

## [1.0.0] - Initial release

### Added

- JSON API routing, controller/service layers, public request validation/normalization, CORS, and standard responses.
- SQL Server SELECT generation with fields/aliases, DISTINCT/TOP, CASE/arithmetic, allow-listed functions, prepared filters, joins, grouping/HAVING, sorting, and compatibility-aware pagination.
- Window functions, filter subqueries, CTE/recursive CTE, UNION/UNION ALL, stored procedures, scalar functions, table-valued functions, and metadata actions.
- SQL execution timing, returned-row counts, and success/error logging.

### Fixed

- BETWEEN date strings can be converted to `YYYYMMDD` integers for integer-family date columns discovered through metadata.

## Planned

Planned work is maintained in [docs/Roadmap.md](docs/Roadmap.md) and is not part of the current API until implemented and tested.
