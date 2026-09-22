# Backend Roadmap

This roadmap covers only the Generic SQL API backend. Frontend dashboards, grids, charts, report widgets, parsers, and UI work are intentionally excluded. A planned item is not part of the public API until code, validation, tests, and documentation all expose it.

## v1.0.0 — Core API & Advanced SQL — Released

- universal public JSON validation and normalization
- SELECT, DISTINCT, TOP (`limit`), aliases, CASE, arithmetic, allow-listed SQL functions
- prepared filters; INNER/LEFT/RIGHT joins; GROUP BY; aggregate HAVING; sorting
- SQL Server compatibility-aware pagination and window functions
- IN/EXISTS filter subqueries, standard/recursive CTE, UNION/UNION ALL
- stored procedure, scalar function, table-valued function, and metadata actions
- stable success/error envelope, execution timing, row count, query/error logging

Current boundaries remain documented in the [capability matrix](Capability-Matrix.md)
and [limitations](Limitations.md). In particular, FULL/CROSS joins, window
partitioning, public INTERSECT/EXCEPT, and general subqueries are not released
JSON Query features.

## v1.1.0 — Windows Runtime & Deployment — Released

- bundled `runtime/windows/php/`
- `start-windows.bat` checks PHP, configuration, ODBC, OpenSSL, encrypted-credential readiness, database connectivity, API path, and ports
- automatic OPcache/log directory creation and validated configurable port selection
- SQL Server/Windows authentication modes and automatic/specific ODBC driver configuration
- cross-platform AES-256-GCM protection for the complete database configuration, environment-managed keys, and verified no-backup one-time setup
- modular `QueryRepository` facade with specialized query builders
- public validation/normalization plus API-contract and ORDER BY/window regression coverage
- database-independent backend test runner and GitHub Actions syntax/logic workflow
- Phase 1 completion coverage for every public function family, JOIN columns,
  nested filter subqueries, CTE/recursive-CTE output scope and pagination,
  UNION/UNION ALL compatibility, routines, metadata, and SQL Resource Mode

## v1.2.0 — CRUD Operations — Current

- INSERT, UPDATE, DELETE, and single-row UPSERT public actions
- deny-by-default write-resource registry with action, table, column, filter, key, and identity allowlists
- live SQL Server metadata validation for types, nullability, defaults, identity, and computed columns
- prepared DML, mandatory UPDATE/DELETE filters, affected-row and safe identity responses
- classified duplicate/constraint errors and database-independent CRUD/security/regression coverage
- SQL Resource Mode support for complex server-owned SQL Server SELECT/CTE queries without JSON Query function/expression limitations

The shipped write registry is intentionally empty and must be configured per
deployment. UPSERT uses one SQL Server MERGE/HOLDLOCK statement and verifies a
matching unfiltered unique index. Bulk writes and application-managed
transactions are not part of this release.

## New Phase 2.1 — Admin Runtime and Configuration UX — Implemented

- independent loopback Admin and API lifecycles with fixed start/stop/restart controls
- configurable API port range, first-available selection, PID/runtime state, and safe health reporting
- System Health, System Info, unified Configuration, and existing Users screens
- backend-enforced Read Data, Write Data, Pagination, Sorting, and Metadata switches
- independently hosted SQL Parser with no Admin, API runtime, session, or database dependency

## New Phase 2.2 — Runtime integration and stabilization — Implemented

- Admin-managed, independent API and SQL Parser lifecycles on Windows and Linux
- separate validated port ranges, service-owned PID state, health, and safe recovery
- automatic Admin-managed database encryption with obsolete setup scripts removed
- platform-aware SQL Server authentication and safe structured query diagnostics
- launcher extension checks through the selected PHP runtime and fixed config root

## v1.3.0 — Transactions — Planned

- begin, commit, rollback, failure handling, and transaction-aware service boundaries

## v1.4.0 — Database Metadata — Planned

The five basic metadata actions already exist. This version is for capabilities not yet implemented: primary/foreign keys, indexes, richer types/constraints, discovery improvements, and caching.

## New Phase 3 — Authorization, roles, API keys, and service controls — Implemented

- common principal and deny-by-default authorization for sessions and API keys
- explicit permissions with SQL/write-resource scopes
- role assignment, session invalidation, and last-admin protection
- one-time-reveal hash-only managed keys with lifecycle controls
- responsive service controls and truthful request-scoped database availability

## New Phase 3.1 — User/auth architecture and role simplification — Implemented

- fixed four-role model with separated backend and frontend access
- System Administrator-only backend configuration and runtime controls
- Application Administrator frontend-user management without backend authority
- removed global feature toggles in favor of authorization

## New Phase 3.2 — Runtime and performance controls — Implemented

- validated `admin.json` runtime settings with atomic immediate reload
- configurable ODBC query timeout, API/login rate limits, and session expiration
- configurable API/Admin JSON body limit and pagination default/maximum
- System Administrator Admin Console controls and database-independent coverage

## v1.5.0 — Advanced API Security — Planned

- editable/custom role administration, advanced audit policy, and distributed rate-limit infrastructure

Session and managed-key authentication, base roles and resource scopes,
administrator user management, CSRF, login throttling, and local API rate limiting are implemented. The
planned work extends that foundation rather than replacing it.

## v1.6.0 — API Improvements — Planned

- versioning, broader production health/status integration, OpenAPI description, and more complete option-level validation
- resolution of documented public/internal edge cases such as `TIMEFROMPARTS`

## v1.7.0 — Performance — Planned

- metadata/query caching evaluation, large-result handling, profiling, connection improvements, and log rotation

## v1.8.0 — Additional Database Providers — Planned

- provider-by-provider implementation and tests for selected databases

Existing MySQL, PostgreSQL, Oracle, and SQLite driver files are not selectable production providers and do not make those databases supported.

## v2.0.0 — Platform & Deployment — Future

- supported Linux deployment/runtime guidance
- containerization and environment-based configuration evaluation
- production deployment/process-manager helpers and optional live integration testing

Version targets may change, but released/current/planned status must always follow the implementation.
