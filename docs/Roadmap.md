# Backend roadmap

The last released version is v1.0.0. The current v2.0.0 development line is
unreleased; implemented post-v1 work is summarized in the
[changelog](../CHANGELOG.md), not repeated here.

Final production documentation, versioning cleanup, test naming cleanup,
cross-platform launcher review, and repository cleanup are complete for the
current release-preparation milestone (Phase 4.13).

Planned items are not part of the public contract until their implementation,
validation, tests, and documentation are merged.

## Phase 5 — Multi-Database Support

- **Database Registry** — define and validate named database targets.
- **Shared SQL Server Configuration** — avoid duplicated connection settings
  while preserving independently controlled credentials and availability.
- **Database Context** — select a permitted database through server-owned
  configuration rather than arbitrary client identifiers.
- **Same-Server Cross-Database Queries** — define safe, explicit boundaries for
  supported SQL Server cross-database reads.
- **Database Authorization** — scope principals and API keys to permitted
  database contexts.
- **Resource Mapping** — bind SQL and write resources to database contexts.
- **Database-Aware Metadata** — return metadata only from authorized contexts.
- **Admin Console** — manage registry entries and safe status information.
- **Testing** — cover isolation, authorization, migration, error handling, and
  deployment-specific SQL Server behavior.

## Phase 6 — Security Verification & Final Security Review

- **Dependency Security** — inventory and scan maintained runtime dependencies.
- **Static Security Analysis** — run appropriate PHP, JavaScript, configuration,
  and secret scanners and triage their output.
- **Authorization Security Testing** — expand role, resource, and identity
  escalation testing.
- **API Security Testing** — verify transport, parsing, validation, throttling,
  and error boundaries against a deployed target.
- **DAST/Security Scanning** — scan representative production-like IIS and Nginx
  deployments.
- **Penetration-Test Preparation** — prepare scope, accounts, data, monitoring,
  recovery, and rules of engagement.
- **Security Architecture Review** — reassess trust boundaries, residual risks,
  and operational controls.
- **Optional Admin MFA** — evaluate without weakening the existing session and
  administrator authorization boundary.
- **Final Security Report** — record tools, versions, target environments,
  findings, remediation, and accepted risks.

## Phase 7 — External Client API Integration & Developer Experience

- **Client Integration Manual** — document authentication, retries, pagination,
  errors, and operational expectations for external clients.
- **Resource Documentation** — publish deployment-specific SQL/write resource
  catalogs without exposing SQL or secrets.
- **Client Integration Examples** — provide maintained examples for supported
  authentication and action families.
- **Postman Collection** — cover representative public requests and environments.
- **OpenAPI Specification** — describe supported HTTP actions and schemas without
  flattening the recursive query model incorrectly.
- **Interactive API Documentation** — derive from the reviewed specification and
  preserve authentication/security warnings.
- **SDKs** — evaluate only after the public contract and versioning policy are
  stable.
- **Webhooks/Callbacks** — document and expose only if implemented in a later
  release; none exist today.

Additional database providers, distributed rate limiting, richer metadata,
transaction APIs, caching, and large-result strategies remain possible future
work but have no release commitment in this roadmap.
