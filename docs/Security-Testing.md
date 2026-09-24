# Security testing

Phase 4.12 verifies security boundaries rather than only checking that security
components exist. Testing is non-destructive, uses isolated temporary runtime
configuration, fake identities and credentials, and targets localhost only.
No production credentials or external systems are used.

This report records the validation performed on 2026-09-24. `TESTED` means a
deterministic repository or localhost check ran successfully. `NOT TESTED` means
the required infrastructure was unavailable. `NOT APPLICABLE` means the current
product has no such feature. `REQUIRES EXTERNAL/STAGING VALIDATION` is not a
claim of production validation.

## Trust boundaries and invariants

| Boundary | Security invariant | Verification |
| --- | --- | --- |
| Browser → API | Exact-origin CORS, session authentication, Secure/HttpOnly/SameSite cookies, and CSRF protect browser mutations independently. | TESTED |
| Machine client → API | API keys are hashed at rest, assigned one server-side backend role, rate limited, and never grant Admin/session access. | TESTED |
| User → resource | Permissions and resource scopes come from the stored identity and authorization configuration, never request role fields. | TESTED |
| User → database operation | Read/write/routine permissions are enforced before database availability or SQL execution. | TESTED without a live database |
| Frontend role → backend authorization | Frontend access permits reads; Application Administrator manages frontend-only users but cannot gain backend write/Admin permissions. | TESTED |
| API key → backend authorization | The stored key role and enabled owner are authoritative; disabled/revoked keys fail immediately. | TESTED |
| SQL resource → SQL Server | Resource IDs resolve only inside the configured server directory, authored resources are read-only single queries, and runtime values remain parameters. | TESTED without live SQL Server |
| Admin Console → runtime | Loopback, session, System Administrator, CSRF, and production external-process ownership gates apply to direct endpoint requests. | TESTED at application/template level |
| Configuration → runtime | Schemas are validated, database credentials are authenticated ciphertext, and malformed/tampered state fails closed. | TESTED |
| Filesystem → application | SQL and backup paths are canonicalized/restricted; secrets, sessions, logs, caches, and runtime state are outside public/versioned content. | TESTED at application/template level |

## Methodology and coverage

The dedicated `tests/SecurityTestingTest.php` suite covers:

- valid, invalid, malformed, disabled, missing, rate-limited, and post-lockout authentication;
- session-ID regeneration, cookie properties, cookie-only strict mode, logout,
  idle/absolute expiration, password/authVersion invalidation, deleted identities,
  and two processes updating the same authenticated session without lost state;
- Read Only, Data Operator, System Administrator, and Application Administrator
  permission/resource boundaries;
- client-supplied backend/frontend role escalation attempts and direct endpoint authorization;
- frontend-only object mutation protection and safe unknown API-key IDs;
- allowed, unknown, guessed, absolute, Windows, encoded, mixed-separator, and
  null-byte-style SQL resource identifiers;
- prepared query/write values plus injected table, field, sort, direction,
  execution-metadata, write-column, and resource identifiers;
- INSERT/UPDATE/DELETE/UPSERT allowlists, full-table guards, prepared parameters,
  configured keys, fixed targets, and `HOLDLOCK` UPSERT generation;
- API-key one-time reveal, hashed storage, role confinement, owner state,
  disabled/revoked behavior, and invalid-key throttling;
- missing/stale CSRF tokens, rotation, session coupling, and API-key independence;
- exact CORS origins, wildcard failure, credential/userinfo rejection, and safe
  endpoint behavior for Origin, OPTIONS, content type, malformed JSON, and methods;
- anonymous, session, API-key, login, reset, and harmless-header rate-limit identities;
- body/page limits and input-shape rejection;
- backup application-tree denial, fixed source set, manifest integrity, and exclusions;
- safe errors, request IDs, audit/log redaction, minimal public health, protected
  detailed health, production headers, and encryption-key/ciphertext failures.

Existing focused suites remain authoritative for deeper cases such as concurrent
writers, complete backup creation/staging, database-configuration migration,
production fatal handling, and all query expression features.

## Results by area

| Area | Result | Notes |
| --- | --- | --- |
| Authentication | TESTED | Generic 401 responses prevent intended account-state enumeration; login throttling and reset passed. |
| Sessions | TESTED | Host-only `/` cookie, Secure in production, HttpOnly, SameSite=Lax, strict cookie-only IDs, regeneration, timeouts, logout, and authVersion invalidation passed. |
| Authorization | TESTED | All four roles were tested against read, write, Admin, and frontend-management boundaries. |
| Privilege escalation | TESTED | Request role/capability fields did not affect the principal created from stored identity or API-key state. |
| BOLA/object access | TESTED / NOT APPLICABLE | Current user/key/config management objects are administrator-global, not tenant-owned. Frontend-only managers cannot mutate backend identities. There is no general per-owner object API. |
| Resource authorization | TESTED | Server resource scopes and traversal-resistant discovery passed. |
| Column/property authorization | NOT APPLICABLE | There is no per-role read-column security model. Write columns are resource allowlisted; read exposure must be controlled through resources/views and database design. |
| SQL injection | TESTED | Values stayed in parameter arrays; identifiers and authored SQL structure were rejected or allowlisted. |
| CRUD security | TESTED | Action/column/resource/filter/key controls and prepared builders passed. Live constraints and triggers were not executed. |
| API keys | TESTED | Secrets were one-time, hashes only were stored, and disabled/revoked/invalid/owner/role cases passed. API-key expiry is not currently a feature. |
| CSRF | TESTED | Session mutations rejected missing/stale tokens; API-key authentication remained independent. |
| CORS | TESTED | Exact origins only; wildcard, userinfo, path, query, fragment, and unsafe scheme inputs fail closed. |
| Rate limiting | TESTED | Local file-backed counters passed. They are intentionally not distributed. |
| Request limits | TESTED | Oversized body, excessive page size, malformed shapes/content/methods passed. Filter volume is bounded by body size rather than a separate filter-count setting. |
| Path/file security | TESTED | Resource and backup paths failed closed; production templates deny sensitive paths. |
| Information disclosure | TESTED | Safe errors/logs did not reveal test credentials, keys, session/CSRF IDs, SQL, stack traces, or filesystem paths. |
| Security headers | TESTED statically / REQUIRES EXTERNAL VALIDATION | All six production policies exist in IIS/Nginx templates; live proxy/error/static responses require staging. |
| Database/secrets | TESTED without SQL Server | Missing/wrong keys, tampering, malformed/versioned envelopes, connection-string injection, and redaction passed. |
| Backup | TESTED | Fixed files, exclusions, checksums, corruption, external staging, and traversal controls pass in isolated fixtures. |
| Health/monitoring | TESTED | Public responses remain minimal; detailed health is System Administrator-protected and secret-free. |
| Audit logging | TESTED | Required lifecycle/denial events, correlation, redaction, file modes, and concurrent JSON Lines passed. |

## Findings

### ST-001 — MEDIUM — unauthenticated API-limit bypass — FIXED

- **Affected component:** `AuthenticationMiddleware` / API rate limiting.
- **Attack scenario:** repeatedly call a protected endpoint with no credential or
  an invalid API key. Authentication previously returned 401 before
  `ApiRateLimitMiddleware`, so the request never consumed the configured API limit.
- **Evidence:** middleware order in both entry points and a deterministic invalid-key
  test reproduced repeated 401 responses without a limiter call.
- **Impact:** a remote caller could generate unbounded authentication/hash/storage
  work relative to the configured single-host API limit. Login had its own limiter,
  so password guessing was not affected.
- **Reproducibility:** deterministic with an isolated two-request limiter.
- **Existing mitigation:** web-server limits, audit logging, and login-specific
  throttling; these did not enforce the protected-endpoint API limit.
- **Fix:** before returning authentication-required, consume the existing
  anonymous IP identity. Authenticated traffic continues using its session/key
  identity later in the pipeline and is not double-counted.
- **Production decision:** fix required before declaring Phase 4.12 complete; fixed and tested.

### ST-002 — LOW — CORS origin userinfo/query validation — FIXED

- **Affected component:** `AdminConfigurationRepository::normalizeOrigin()`.
- **Attack scenario:** save an origin containing URL userinfo, a query, or a
  fragment. A multi-key `isset` expression rejected it only if every optional
  component existed simultaneously and otherwise normalized components away.
- **Evidence:** `https://user:pass@good.example.test` was accepted before the fix.
- **Impact:** configuration could differ from operator intent. Browser `Origin`
  serialization does not include userinfo/query/fragment, limiting direct exploitability.
- **Reproducibility:** deterministic validator call.
- **Existing mitigation:** exact normalized comparison and wildcard rejection.
- **Fix:** reject each forbidden URL component independently.
- **Production decision:** fixed and regression tested.

### ST-003 — MEDIUM — no per-role read-column authorization — OPEN DESIGN LIMITATION

- **Affected component:** JSON Query/SQL Resource read authorization.
- **Attack scenario:** a role allowed to query a table/resource requests a sensitive
  column that is otherwise valid for that query mode.
- **Evidence:** authorization scopes permissions and SQL resources, not individual
  read columns. This is explicitly distinct from CRUD write-column allowlists.
- **Impact:** an overly broad resource/table permission can expose columns to all
  identities holding that permission.
- **Reproducibility:** configuration-dependent.
- **Existing mitigation:** server-owned SQL resources, views, resource scopes,
  metadata validation, and database permissions.
- **Recommendation:** expose least-privilege views/resources and avoid granting
  generic table reads containing sensitive columns. A future column policy would
  require an intentional architecture/public-contract phase.
- **Production decision:** not a hidden bypass of the current resource-level model;
  operator review is required before production.

### ST-004 — LOW — no independent filter-count limit — OPEN HARDENING OPPORTUNITY

- **Affected component:** query and SQL-resource request validation.
- **Attack scenario:** an authenticated caller packs many individually valid filters
  into a body below the maximum byte size.
- **Evidence:** validators enforce shape/depth/operators but do not define a separate
  filter-count threshold.
- **Impact:** bounded per-request SQL generation/parameter pressure and possible
  database rejection; not arbitrary SQL or unbounded body allocation.
- **Existing mitigation:** maximum request bytes, API rate limits, query timeout,
  SQL Server parameter behavior, and worker/proxy limits.
- **Recommendation:** tune the body cap and measure representative maximum filter
  usage in staging; add a configurable count only with a defined compatibility contract.
- **Production decision:** not blocking; residual low-risk resource amplification.

### ST-005 — LOW — single-host rate-limit state — ACCEPTED LIMITATION

- **Affected component:** login/API rate limit storage.
- **Attack scenario:** distribute traffic across application hosts that do not share
  a counter store.
- **Evidence:** counters are hashed files protected by local locks.
- **Impact:** limits apply per host rather than globally.
- **Existing mitigation:** accurate same-host concurrency and documented production
  single-host boundary.
- **Recommendation:** use an approved shared limiter/WAF before multi-host deployment.
- **Production decision:** acceptable for the documented topology; external validation required.

### ST-006 — INFORMATIONAL — plaintext credential compatibility

- **Affected component:** legacy database credential resolver.
- **Scenario/evidence:** legacy plaintext/password-only formats remain readable for
  migration compatibility; Admin saves write authenticated AES-256-GCM configuration.
- **Impact:** an operator who leaves a legacy plaintext file in place does not gain
  encryption-at-rest protection.
- **Mitigation/recommendation:** run migration or save configuration through Admin,
  verify `encrypted: true`, restrict file ACLs, and keep the key separately.
- **Production decision:** encrypted production configuration is required operationally.

### ST-007 — INFORMATIONAL — internal SQL Parser trust boundary

- **Affected component:** SQL Parser listener.
- **Scenario/evidence:** the parser is database-free and has no user authentication;
  deployment templates place it on a separate loopback/internal listener.
- **Impact:** unintended public exposure would offer parsing functionality and a
  resource-consumption surface, not database authorization.
- **Mitigation/recommendation:** retain loopback/internal bindings and firewall policy.
- **Production decision:** verify on the target host.

No unresolved CRITICAL or HIGH finding was identified.

## Static analysis

The local review searched application, API, Admin, scripts, core, database, and
tests for command execution, `eval`, unsafe deserialization, dynamic includes,
filesystem mutation, direct ODBC execution, secret literals, debug output, and
SQL concatenation.

- **Confirmed vulnerabilities:** ST-001 and ST-002, both fixed.
- **False positives:** SQL strings in builders are structural SQL assembled from
  validated/metadata-confirmed identifiers; runtime values use placeholders.
- **Accepted behavior:** the local development process manager uses fixed command
  arrays plus numeric PIDs/service ownership checks; no client command is executed.
- **Hardening opportunities:** ST-004 and continued staging review of deployment ACLs.
- **No `eval` or application `unserialize`:** TESTED.
- **Randomness/passwords:** `random_bytes`, `password_hash`, `password_verify`, and
  constant-time comparisons are used in their security roles.

Tools used were `rg` for source inspection, PHP's parser/linter, Node's syntax
checker, `bash -n`, Python's standard XML parser, `curl`, Git whitespace/diff
checks, and the repository test harness. `shellcheck`, PHPStan, and Semgrep were
not installed, so those tools were **NOT TESTED** rather than silently claimed.

## Dependency audit

There is no Composer manifest/lock and no Backend npm manifest/lock. The Backend
has no package-manager runtime dependency set to submit to `composer audit` or
`npm audit`; those audits are therefore **NOT APPLICABLE** here. PHP, web server,
ODBC driver, OpenSSL, and operating-system patch status are external deployment
dependencies and require the host's supported security-update process.

## Local application testing

Deterministic localhost requests covered exact/disallowed origins, OPTIONS,
unsupported methods/content types, malformed JSON, missing authentication,
client role fields, a hidden Admin action, a traversal-like route, oversized
bodies, health disclosure, and error/header behavior. Invalid/malformed API-key
cases were exercised in the isolated application suite rather than against a
live credential store. No external DAST scanner was installed or run.
Aggressive fuzzing, destructive payloads, public scanning, live SQL injection,
and production credential testing were **NOT TESTED** by design.

## Production security checklist

1. Run the full normal and `php -n` suites and retain results with the release.
2. Verify encrypted database configuration and separately provisioned key under
   the actual FastCGI/FPM identity.
3. Confirm all role/resource assignments and explicitly review sensitive columns.
4. Test session, CSRF, CORS, API keys, and each role through deployed HTTPS.
5. Verify public health is minimal and `admin.health` is inaccessible without a
   System Administrator session and valid CSRF where mutation applies.
6. Verify IIS/Nginx denies configuration, source, logs, runtime, sessions,
   backups, `.git`, temporary files, and non-entry PHP files.
7. Inspect normal/error/static/redirect responses for the complete header policy.
8. Exercise request/rate/query/proxy/FastCGI limits under controlled staging load.
9. Confirm Admin and SQL Parser bindings remain loopback/internal only.
10. Review audit/web/PHP logs for coverage and absence of secrets.
11. Run host/package vulnerability tooling for PHP, IIS/Nginx, OpenSSL, ODBC,
    and the operating system.
12. Perform an authorized external penetration test against staging before public launch.

Live SQL Server authorization/constraints/triggers, IIS/FastCGI, Nginx/PHP-FPM,
TLS, reverse proxies, distributed rate limiting, WAF behavior, OS ACLs, and
multi-worker load remain **REQUIRES EXTERNAL/STAGING VALIDATION**.
