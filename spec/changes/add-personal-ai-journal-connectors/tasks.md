# Implementation tasks

Status: deployed and API-verified in production; actual personal chat-client
configuration remains pending. See [execution record](execution.md).

1. [ ] **Confirm deployment and client prerequisites.** Identify pilot account UUID,
   HTTPS domains, actual required bean fields/options, and ChatGPT Actions support.
   Record Claude transport/auth findings separately; verify database update hooks.
2. [x] **Specify contracts.** Publish integration OpenAPI, error/pagination shapes,
   supported fields, read-only provenance, request ID semantics, and scoped tools.
3. [x] **Add provenance storage.** Create/export Drupal fields and safe upgrade
   handling; historical records stay unknown. Protect fields from client writes
   across entity APIs and preserve metadata after edits and credential removal.
4. [x] **Extract shared journal services.** Reuse current validation, field mapping,
   access checks and presentation from headless controllers. Regression-check
   existing session-based create/edit flows and their CSRF requirements.
5. [x] **Implement personal connector authentication.** Add hashed scoped expiring
   credentials, admin-only issue/revoke, account allowlist, explicit route auth,
   owner-only queries, blocked-account checks, no-store responses and rate limits.
6. [x] **Implement read/create integration routes.** Add field options, search/list,
   detail and creation. Set provenance from authenticated connection context;
   add payload-bound atomic idempotency and return saved app URLs.
7. [x] **Render provenance in both UIs.** Extend API/frontend types, bean cards and
   detail views, and legacy Drupal preprocessing/Twig. Verify light/dark,
   accessible labels, historical entries and manual creates.
8. [ ] **Configure private GPT Actions.** Import schema, store credential securely,
   declare consequential creation, and document missing-field/confirmation/retry
   behavior. Exercise create and read in the owner's actual ChatGPT clients.
9. [ ] **Verify and release ChatGPT milestone.** Run kernel/contract tests for
   account isolation, scopes, expiry/revocation, invalid fields, provenance
   spoofing, concurrent replay and mismatched payloads. Run frontend checks and
   browser verification; deploy additive backend/UI and record an end-to-end save.
10. [ ] **Implement Claude MCP milestone.** Choose maintained transport/auth
    dependencies after compatibility spike. Implement tools using shared services,
    owner-limited authorization and distinct connection identity. Test actual
    Claude tool discovery, reads, confirmed creation, retries and revocation;
    verify the saved entry shows Claude provenance. If OAuth is required, include
    discovery, grant isolation and PKCE tests before release.
11. [x] **Document operation and extension.** Record credential rotation, disconnect,
    rollback, troubleshooting, client limitations and adding provider identities.
    Assess Gemini separately against verified client support; do not mark it
    supported solely because the API or MCP server exists.

## Partial task evidence

- **1:** Local Drupal/PHP/DB confirmed, deployed hook ordering verified. Production
  pilot account UUID and external domains are selected and verified. Consumer
  accounts have not been opened/configured.
- **8:** OpenAPI, consequential write annotation and GPT instructions delivered.
  Import/authentication in the private GPT and mobile verification remain pending.
- **9:** Local kernel/unit/frontend, real concurrent HTTP and source label checks
  pass. CI includes connector kernel coverage. Production deployment, runtime
  configuration and HTTPS create/replay/source verification pass. Live chat-to-app
  acceptance remains pending.
- **10:** Official PHP SDK transport and connection-scoped sessions implemented;
  HTTP handshake/discovery/create/replay/revocation verified. Anthropic currently
  documents fixed bearer request headers, so no OAuth implementation is needed
  for that client configuration. Real Claude account acceptance remains pending.
