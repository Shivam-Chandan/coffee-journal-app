# Implementation evidence

Date: November 1, 2026. Working-tree implementation; no deployment or release
revision has been created for this change.

## Delivered

- Dedicated authenticated REST field-options/list/search/detail/create routes.
- App-issued hashed, scoped, expiring/revocable connections, restricted to one
  explicitly configured active account UUID. Access defaults to disabled.
- Shared browser/connector field mapping and entity validation in `JournalInput`.
- Database-transactional, payload-bound create receipts and advisory locks.
- Three exported provenance fields, denied through field access and enforced in
  node presave for alternate write paths. Existing records remain unknown;
  normal app creations are app-origin; human edits retain creation metadata.
- Provider badges in React cards/details and legacy Drupal teaser/detail views.
- OpenAPI for private GPT Actions and copyable setup/instruction documentation.
- Official `mcp/sdk` v0.9.0 adapter over Acquia/PHP HTTP. Registered tools reuse
  journal services, schemas derive from actual Drupal field definitions, and
  session storage is expiring and isolated per connection. Normal JSON and SDK
  SSE responses are supported through Symfony Response/StreamedResponse.
- CI kernel test target expanded to include connector contracts.

## Verification

Environment: local Drupal **11.3.8**, PHP **8.3.35**, MySQL-connected development
site; isolated SQLite kernel fixtures; frontend Node **22.23.2**.

Commands, run from repository root unless specified:

```sh
composer validate --no-check-publish
vendor/bin/phpunit -c docroot/core/phpunit.xml.dist docroot/modules/custom/coffee_journal_api/tests/phpunit
SIMPLETEST_DB=sqlite://localhost/:memory: vendor/bin/phpunit -c docroot/core/phpunit.xml.dist docroot/modules/custom/coffee_journal_api/tests/src/Kernel
# From frontend/:
DRUPAL_ORIGIN=https://eeschandan1dev.prod.acquia-sites.com npm run build:vercel
npm test
git diff --check
```

- Composer validation passes. SDK installation added nine packages; previously
  dev-only transitive dependencies now needed at runtime moved into `packages`.
  Composer reported zero existing-package updates/removals.
- Backend unit tests: **70 tests / 304 assertions**, passing.
- Backend kernel tests: **14 tests / 233 assertions**, passing. Covers existing
  journal behavior plus credential lifecycle, blocked/changed pilot accounts,
  admin ownership isolation, immutable source, conflicting and identical replay,
  transaction rollback, transport bounds/rate limits, Actions field contract,
  MCP discovery/creation/replay, cross-connection session rejection and Origin
  rejection. Deprecation notices remain in upstream MCP/Opis code and the existing
  headless test class; no core or vendor files were modified.
- The exact CI invocation (`--no-configuration --bootstrap
  docroot/core/tests/bootstrap.php` against the kernel test directory) also passes
  all 14 tests / 233 assertions without reported issues.
- Frontend: build/typecheck passes; **20 tests** pass; generated Vercel output
  contains **zero functions**.
- A disposable local HTTP smoke fixture, using a four-worker PHP server and the
  real MySQL-backed site, passed: missing/invalid bearer, field metadata, four
  simultaneous creates yielding one node, repeated save, changed-payload conflict,
  source spoof rejection, bearer isolation from browser routes, cookie-only
  connector rejection, browser CSRF protection, read-only scope, immediate token
  revocation, and MCP initialization/discovery/create/replay/revocation.
- The same fixture rendered real saved coffee nodes in the legacy theme in full
  and teaser modes and verified both source labels. Temporary accounts,
  credentials and coffee entries were removed after checks.
- Browser fixture verification via agent-browser confirmed ChatGPT/Claude card
  labels, no badge for manual/legacy-compatible cards, and detailed source/date
  display at mobile width (390px), with light/dark journal presentation checked.
  React browser data was mocked for visual checks; real persistence and ownership
  were verified separately by the HTTP and kernel checks above.

## Findings and implementation decisions

- This local site needed `DRUPAL_HASH_SALT` supplied for CLI cache rebuilds. A
  development-only value was supplied to local test processes, not committed.
- The local database's last-installed node field metadata was incomplete before
  introducing new fields. It was reconciled locally against its existing field
  definitions to permit real-node verification. This was a local environment
  repair, not a production migration or an automatic update hook.
- Required fields on the current site include several taste/date/quantity values;
  clients must use field metadata and ask for missing required values rather than
  assume title alone is enough. MCP schemas include the deployed required fields.
- An initial frontend test attempt used the system Node runtime and failed on
  localStorage. Running with the project's required Node 22 passed.
- Anthropic's custom connector help documentation, checked November 1, 2026,
  explicitly supports fixed request headers/bearer credentials. Setup is documented
  for that path; no claim is made that the owner's actual account was configured.
- Creation tool annotations describe additive writes. ChatGPT uses its
  consequential-operation flag; MCP advertises write behavior and includes
  confirmation instructions. Actual consumer-client confirmation UX must be
  verified during activation.

## Remaining activation steps

1. Select the owner's Drupal account UUID and final backend/frontend origins.
2. Deploy code/dependencies/config and run the existing update/import/cache flow.
3. Set environment account/app URL settings; issue separate production credentials
   in a private administrator terminal.
4. Import Actions into a private GPT, configure its credential, and test web/mobile
   create-to-journal behavior and confirmation.
5. Configure Claude's remote MCP connector with its separate fixed bearer header;
   verify tools, confirmation, creation, retry and revocation in that account.
6. Keep Gemini deferred until a specific supported consumer-client integration is
   verified. Archive only once the agreed deployment/client acceptance is complete.
